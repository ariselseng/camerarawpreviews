<?php

namespace OCA\CameraRawPreviews\Tests\Support;

/**
 * Builds real TIFF/DNG-like byte streams for the embedded-JPEG-XL tests.
 *
 * Unlike {@see TiffBuilder} (which only writes a single IFD with inline SHORT
 * values), this builder can write a full TIFF header, an IFD0, any number of
 * SubIFDs (tag 0x014A), multi-value tags stored out of line, strip data and
 * tiled data. The production TIFF parser is exercised against these files; no
 * parser is mocked.
 *
 * Everything is little-endian by default (like the DNG samples) but the
 * big-endian path is available for completeness.
 */
final class DngTiffBuilder
{
    public const TYPE_BYTE = 1;
    public const TYPE_ASCII = 2;
    public const TYPE_SHORT = 3;
    public const TYPE_LONG = 4;
    public const TYPE_RATIONAL = 5;
    public const TYPE_UNDEFINED = 7;
    public const TYPE_IFD = 13;

    public const TAG_NEW_SUBFILE_TYPE = 0x00FE;
    public const TAG_IMAGE_WIDTH = 0x0100;
    public const TAG_IMAGE_LENGTH = 0x0101;
    public const TAG_BITS_PER_SAMPLE = 0x0102;
    public const TAG_COMPRESSION = 0x0103;
    public const TAG_PHOTOMETRIC = 0x0106;
    public const TAG_STRIP_OFFSETS = 0x0111;
    public const TAG_ORIENTATION = 0x0112;
    public const TAG_SAMPLES_PER_PIXEL = 0x0115;
    public const TAG_ROWS_PER_STRIP = 0x0116;
    public const TAG_STRIP_BYTE_COUNTS = 0x0117;
    public const TAG_PLANAR_CONFIG = 0x011C;
    public const TAG_TILE_WIDTH = 0x0142;
    public const TAG_TILE_LENGTH = 0x0143;
    public const TAG_TILE_OFFSETS = 0x0144;
    public const TAG_TILE_BYTE_COUNTS = 0x0145;
    public const TAG_SUB_IFDS = 0x014A;

    public const COMPRESSION_JPEG = 7;
    public const COMPRESSION_JXL_DNG = 52546;
    public const COMPRESSION_JXL_LEGACY = 50002;
    public const PHOTOMETRIC_BLACK_IS_ZERO = 1;
    public const PHOTOMETRIC_RGB = 2;
    public const PHOTOMETRIC_YCBCR = 6;
    public const PHOTOMETRIC_CFA = 32803;
    public const PHOTOMETRIC_LINEAR_RAW = 34892;

    private bool $little = true;
    private ?int $orientation = null;
    private ?array $ifd0 = null;
    private ?int $subIfdsOffset = null;

    /** @var array<int, array> */
    private array $subImages = [];

    public static function create(): self
    {
        return new self();
    }

    public function littleEndian(bool $little = true): self
    {
        $this->little = $little;
        return $this;
    }

    /** Orientation tag written to IFD0 (unless the IFD0 spec sets its own). */
    public function orientation(int $orientation): self
    {
        $this->orientation = $orientation;
        return $this;
    }

    /** Use $spec as the IFD0 image (e.g. the small JPEG preview). */
    public function ifd0Image(array $spec): self
    {
        $this->ifd0 = $spec;
        return $this;
    }

    /**
     * Point the IFD0 SubIFDs entry at an arbitrary offset. Used to build a
     * malformed file whose SubIFD table lies outside the file.
     */
    public function subIfdsOffset(int $offset): self
    {
        $this->subIfdsOffset = $offset;
        return $this;
    }

    /** Append a SubIFD image. */
    public function addSubImage(array $spec): self
    {
        $this->subImages[] = $spec;
        return $this;
    }

    /**
     * Add a JXL preview SubIFD. $bytes is either a JXL container or a bare
     * codestream; $overrides can replace any tag (compression, photometric,
     * newSubfileType, strips, stripOffsets, ...).
     */
    public function addJxlPreview(string $bytes, int $width, int $height, array $overrides = []): self
    {
        return $this->addSubImage(array_merge([
            'width' => $width,
            'height' => $height,
            'compression' => self::COMPRESSION_JXL_DNG,
            'photometric' => self::PHOTOMETRIC_RGB,
            'newSubfileType' => 1,
            'samplesPerPixel' => 3,
            'bitsPerSample' => [8, 8, 8],
            'strips' => [$bytes],
        ], $overrides));
    }

    /** Add an ordinary JPEG preview SubIFD. */
    public function addJpegPreview(string $jpeg, int $width, int $height, array $overrides = []): self
    {
        return $this->addSubImage(array_merge([
            'width' => $width,
            'height' => $height,
            'compression' => self::COMPRESSION_JPEG,
            'photometric' => self::PHOTOMETRIC_YCBCR,
            'newSubfileType' => 1,
            'samplesPerPixel' => 3,
            'bitsPerSample' => [8, 8, 8],
            'strips' => [$jpeg],
        ], $overrides));
    }

    /** Make IFD0 itself the small JPEG preview (like the real DNG sample). */
    public function primaryJpeg(string $jpeg, int $width, int $height, array $overrides = []): self
    {
        return $this->ifd0Image(array_merge([
            'width' => $width,
            'height' => $height,
            'compression' => self::COMPRESSION_JPEG,
            'photometric' => self::PHOTOMETRIC_YCBCR,
            'newSubfileType' => 0,
            'samplesPerPixel' => 3,
            'bitsPerSample' => [8, 8, 8],
            'strips' => [$jpeg],
        ], $overrides));
    }

    public function build(): string
    {
        $specs = [$this->normalizedIfd0()];
        foreach ($this->subImages as $sub) {
            $specs[] = $this->normalize($sub, false);
        }

        // 1. Assign the IFD offsets. IFD size = count(2) + 12*n + next(4).
        $ifdOffsets = [];
        $pos = 8;
        foreach ($specs as $i => $spec) {
            $ifdOffsets[$i] = $pos;
            $pos += 2 + 12 * count($this->tagIds($spec, $i === 0)) + 4;
        }

        // 2. The SubIFD offset array (only needed when more than one SubIFD;
        //    a single offset fits inline in the IFD0 entry).
        $subIfdArrayOffset = null;
        if (count($this->subImages) > 1) {
            $subIfdArrayOffset = $this->align4($pos);
            $pos = $subIfdArrayOffset + 4 * count($this->subImages);
        }

        // 3. Assign data offsets (out-of-line tag values and strip/tile data).
        $pos = $this->align4($pos);
        $data = [];
        foreach ($specs as $i => $spec) {
            $data[$i] = [
                'bps' => null,
                'stripOffsets' => null,
                'stripByteCounts' => null,
                'tileOffsets' => null,
                'tileByteCounts' => null,
                'extra' => [],
                'strips' => [],
                'tiles' => [],
            ];

            if (count($spec['bitsPerSample']) * 2 > 4) {
                $data[$i]['bps'] = $pos;
                $pos += $this->align4(count($spec['bitsPerSample']) * 2);
            }

            $stripCount = count($spec['strips']);
            if ($stripCount > 1) {
                $data[$i]['stripOffsets'] = $pos;
                $pos += $this->align4($stripCount * 4);
                $data[$i]['stripByteCounts'] = $pos;
                $pos += $this->align4($stripCount * 4);
            }

            $tileCount = is_array($spec['tiles']) ? count($spec['tiles']) : 0;
            if ($tileCount > 1) {
                $data[$i]['tileOffsets'] = $pos;
                $pos += $this->align4($tileCount * 4);
                $data[$i]['tileByteCounts'] = $pos;
                $pos += $this->align4($tileCount * 4);
            }

            foreach ($spec['extraTags'] as $tag => [$type, $values]) {
                $packed = $this->packValues($type, $values);
                if (strlen($packed) > 4) {
                    $data[$i]['extra'][$tag] = $pos;
                    $pos += $this->align4(strlen($packed));
                }
            }

            foreach ($spec['strips'] as $j => $bytes) {
                $data[$i]['strips'][$j] = $pos;
                $pos += $this->align4(strlen($bytes));
            }

            if (is_array($spec['tiles'])) {
                foreach ($spec['tiles'] as $j => $tile) {
                    $data[$i]['tiles'][$j] = $pos;
                    $pos += $this->align4(strlen($tile['bytes']));
                }
            }
        }

        $total = $pos;
        $buf = str_repeat("\x00", $total);

        // Header.
        $buf = substr_replace($buf, $this->little ? 'II' : 'MM', 0, 2);
        $buf = substr_replace($buf, $this->packU16(42), 2, 2);
        $buf = substr_replace($buf, $this->packU32(8), 4, 4);

        // SubIFD offset array.
        if ($subIfdArrayOffset !== null) {
            $subOffsets = [];
            for ($i = 0; $i < count($this->subImages); $i++) {
                $subOffsets[] = $ifdOffsets[$i + 1];
            }
            $buf = substr_replace($buf, $this->packValues(self::TYPE_LONG, $subOffsets), $subIfdArrayOffset, 4 * count($subOffsets));
        }

        // IFDs.
        foreach ($specs as $i => $spec) {
            $isIfd0 = $i === 0;
            $tags = $this->buildTagMap($spec, $isIfd0, $ifdOffsets, $subIfdArrayOffset, $data[$i]);
            $base = $ifdOffsets[$i];

            $buf = substr_replace($buf, $this->packU16(count($tags)), $base, 2);
            $entryPos = $base + 2;
            foreach ($tags as $tag => [$type, $values, $valueOffset]) {
                $buf = substr_replace($buf, $this->packU16($tag), $entryPos, 2);
                $buf = substr_replace($buf, $this->packU16($type), $entryPos + 2, 2);
                $buf = substr_replace($buf, $this->packU32(count($values)), $entryPos + 4, 4);

                $packed = $this->packValues($type, $values);
                if ($valueOffset !== null) {
                    $buf = substr_replace($buf, $this->packU32($valueOffset), $entryPos + 8, 4);
                    $buf = substr_replace($buf, $packed, $valueOffset, strlen($packed));
                } else {
                    $buf = substr_replace($buf, str_pad($packed, 4, "\x00"), $entryPos + 8, 4);
                }

                $entryPos += 12;
            }
            $buf = substr_replace($buf, $this->packU32(0), $entryPos, 4); // next IFD

            // Strip / tile payloads.
            foreach ($spec['strips'] as $j => $bytes) {
                $buf = substr_replace($buf, $bytes, $data[$i]['strips'][$j], strlen($bytes));
            }
            if (is_array($spec['tiles'])) {
                foreach ($spec['tiles'] as $j => $tile) {
                    $buf = substr_replace($buf, $tile['bytes'], $data[$i]['tiles'][$j], strlen($tile['bytes']));
                }
            }
        }

        return $buf;
    }

    // ---------------------------------------------------------------------

    private function normalizedIfd0(): array
    {
        $spec = $this->ifd0 ?? [
            // A harmless 1x1 primary image so the TIFF is structurally valid.
            'width' => 1,
            'height' => 1,
            'compression' => 1,
            'photometric' => self::PHOTOMETRIC_BLACK_IS_ZERO,
            'newSubfileType' => 0,
            'samplesPerPixel' => 1,
            'bitsPerSample' => [8],
            // A valid (if trivial) primary image keeps the TIFF well-formed for
            // parsers that insist IFD0 has strip data. Tests that need Imagick
            // to fail explicitly pass omitStripTags => true.
            'strips' => ["\x00"],
            'rowsPerStrip' => 1,
        ];

        if ($this->orientation !== null && ($spec['orientation'] ?? null) === null) {
            $spec['orientation'] = $this->orientation;
        }
        $spec['subIfdsOffset'] = $this->subIfdsOffset;

        return $this->normalize($spec, true);
    }

    private function normalize(array $spec, bool $isIfd0): array
    {
        return array_merge([
            'width' => null,
            'height' => null,
            'compression' => 1,
            'photometric' => self::PHOTOMETRIC_RGB,
            'newSubfileType' => $isIfd0 ? 0 : 1,
            'samplesPerPixel' => 3,
            'bitsPerSample' => [8, 8, 8],
            'orientation' => null,
            'strips' => [],
            'tiles' => null,
            'tileWidth' => null,
            'tileHeight' => null,
            'rowsPerStrip' => null,
            'stripOffsets' => null,
            'stripByteCounts' => null,
            'tileOffsets' => null,
            'tileByteCounts' => null,
            'omitStripTags' => false,
            'extraTags' => [],
            'subIfdsOffset' => null,
        ], $spec);
    }

    /**
     * Tag ids present in an IFD, sorted. Used for the IFD size calculation.
     *
     * @return int[]
     */
    private function tagIds(array $spec, bool $isIfd0): array
    {
        $tags = [];
        if ($spec['width'] !== null) {
            $tags[] = self::TAG_IMAGE_WIDTH;
        }
        if ($spec['height'] !== null) {
            $tags[] = self::TAG_IMAGE_LENGTH;
        }
        if (!empty($spec['bitsPerSample'])) {
            $tags[] = self::TAG_BITS_PER_SAMPLE;
        }
        $tags[] = self::TAG_COMPRESSION;
        $tags[] = self::TAG_PHOTOMETRIC;
        if ($spec['samplesPerPixel'] !== null) {
            $tags[] = self::TAG_SAMPLES_PER_PIXEL;
        }
        $tags[] = self::TAG_NEW_SUBFILE_TYPE;
        if ($spec['orientation'] !== null) {
            $tags[] = self::TAG_ORIENTATION;
        }

        if (!$spec['omitStripTags']) {
            if (is_array($spec['tiles'])) {
                $tags[] = self::TAG_TILE_WIDTH;
                $tags[] = self::TAG_TILE_LENGTH;
                $tags[] = self::TAG_TILE_OFFSETS;
                $tags[] = self::TAG_TILE_BYTE_COUNTS;
            } elseif (!empty($spec['strips']) || $spec['stripOffsets'] !== null) {
                $tags[] = self::TAG_ROWS_PER_STRIP;
                $tags[] = self::TAG_STRIP_OFFSETS;
                $tags[] = self::TAG_STRIP_BYTE_COUNTS;
            }
        }

        if ($isIfd0 && count($this->subImages) > 0) {
            $tags[] = self::TAG_SUB_IFDS;
        }

        foreach (array_keys($spec['extraTags']) as $tag) {
            $tags[] = $tag;
        }

        $tags = array_values(array_unique($tags));
        sort($tags);
        return $tags;
    }

    /**
     * @return array<int, array{0:int,1:array,2:?int}> tag => [type, values, dataOffset]
     */
    private function buildTagMap(array $spec, bool $isIfd0, array $ifdOffsets, ?int $subIfdArrayOffset, array $data): array
    {
        $map = [];

        if ($spec['width'] !== null) {
            $map[self::TAG_IMAGE_WIDTH] = [self::TYPE_LONG, [$spec['width']], null];
        }
        if ($spec['height'] !== null) {
            $map[self::TAG_IMAGE_LENGTH] = [self::TYPE_LONG, [$spec['height']], null];
        }
        if (!empty($spec['bitsPerSample'])) {
            $map[self::TAG_BITS_PER_SAMPLE] = [
                self::TYPE_SHORT,
                $spec['bitsPerSample'],
                $data['bps'],
            ];
        }
        $map[self::TAG_COMPRESSION] = [self::TYPE_SHORT, [$spec['compression']], null];
        $map[self::TAG_PHOTOMETRIC] = [self::TYPE_SHORT, [$spec['photometric']], null];
        if ($spec['samplesPerPixel'] !== null) {
            $map[self::TAG_SAMPLES_PER_PIXEL] = [self::TYPE_SHORT, [$spec['samplesPerPixel']], null];
        }
        $map[self::TAG_NEW_SUBFILE_TYPE] = [self::TYPE_LONG, [$spec['newSubfileType']], null];
        if ($spec['orientation'] !== null) {
            $map[self::TAG_ORIENTATION] = [self::TYPE_SHORT, [$spec['orientation']], null];
        }

        if (!$spec['omitStripTags']) {
            if (is_array($spec['tiles'])) {
                $map[self::TAG_TILE_WIDTH] = [
                    self::TYPE_LONG,
                    [$spec['tileWidth'] ?? $spec['width']],
                    null,
                ];
                $map[self::TAG_TILE_LENGTH] = [
                    self::TYPE_LONG,
                    [$spec['tileHeight'] ?? $spec['height']],
                    null,
                ];

                $tileOffsets = $spec['tileOffsets'] ?? [];
                $tileCounts = $spec['tileByteCounts'] ?? [];
                foreach ($spec['tiles'] as $j => $tile) {
                    if ($spec['tileOffsets'] === null) {
                        $tileOffsets[] = $data['tiles'][$j];
                    }
                    if ($spec['tileByteCounts'] === null) {
                        $tileCounts[] = strlen($tile['bytes']);
                    }
                }
                $map[self::TAG_TILE_OFFSETS] = [
                    self::TYPE_LONG,
                    $tileOffsets,
                    count($tileOffsets) > 1 ? $data['tileOffsets'] : null,
                ];
                $map[self::TAG_TILE_BYTE_COUNTS] = [
                    self::TYPE_LONG,
                    $tileCounts,
                    count($tileCounts) > 1 ? $data['tileByteCounts'] : null,
                ];
            } elseif (!empty($spec['strips']) || $spec['stripOffsets'] !== null) {
                $map[self::TAG_ROWS_PER_STRIP] = [
                    self::TYPE_LONG,
                    [$spec['rowsPerStrip'] ?? ($spec['height'] ?? 1)],
                    null,
                ];

                $stripOffsets = $spec['stripOffsets'] ?? [];
                $stripCounts = $spec['stripByteCounts'] ?? [];
                foreach ($spec['strips'] as $j => $bytes) {
                    if ($spec['stripOffsets'] === null) {
                        $stripOffsets[] = $data['strips'][$j];
                    }
                    if ($spec['stripByteCounts'] === null) {
                        $stripCounts[] = strlen($bytes);
                    }
                }
                $map[self::TAG_STRIP_OFFSETS] = [
                    self::TYPE_LONG,
                    $stripOffsets,
                    count($stripOffsets) > 1 ? $data['stripOffsets'] : null,
                ];
                $map[self::TAG_STRIP_BYTE_COUNTS] = [
                    self::TYPE_LONG,
                    $stripCounts,
                    count($stripCounts) > 1 ? $data['stripByteCounts'] : null,
                ];
            }
        }

        if ($isIfd0 && count($this->subImages) > 0) {
            $subOffsets = [];
            for ($i = 0; $i < count($this->subImages); $i++) {
                $subOffsets[] = $ifdOffsets[$i + 1];
            }
            $valueOffset = count($subOffsets) > 1 ? $subIfdArrayOffset : null;
            if ($spec['subIfdsOffset'] !== null) {
                $valueOffset = $spec['subIfdsOffset'];
            }
            $map[self::TAG_SUB_IFDS] = [
                self::TYPE_LONG,
                $subOffsets,
                $valueOffset,
            ];
        }

        foreach ($spec['extraTags'] as $tag => [$type, $values]) {
            $packed = $this->packValues($type, $values);
            $map[$tag] = [$type, $values, strlen($packed) > 4 ? $data['extra'][$tag] : null];
        }

        ksort($map);
        return $map;
    }

    // ---------------------------------------------------------------------

    private function align4(int $n): int
    {
        return ($n + 3) & ~3;
    }

    private function packU16(int $v): string
    {
        return $this->little ? pack('v', $v) : pack('n', $v);
    }

    private function packU32(int $v): string
    {
        return $this->little ? pack('V', $v) : pack('N', $v);
    }

    private function packValues(int $type, array $values): string
    {
        $out = '';
        foreach ($values as $value) {
            $out .= match ($type) {
                self::TYPE_BYTE, self::TYPE_ASCII, self::TYPE_UNDEFINED => chr(((int)$value) & 0xFF),
                self::TYPE_SHORT => $this->packU16((int)$value),
                self::TYPE_LONG, self::TYPE_IFD => $this->packU32((int)$value),
                self::TYPE_RATIONAL => $this->packU32((int)$value[0]) . $this->packU32((int)$value[1]),
                default => throw new \InvalidArgumentException('Unsupported TIFF type ' . $type),
            };
        }
        return $out;
    }
}
