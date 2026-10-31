<?php

namespace OCA\CameraRawPreviews\Preview;

use OCA\CameraRawPreviews\Preview\Support\FileReader;
use OCA\CameraRawPreviews\Preview\Support\JpegOrienter;
use OCA\CameraRawPreviews\Preview\Support\JpegValidator;
use OCA\CameraRawPreviews\Preview\Support\JxlConverter;

/**
 * Extracts a large embedded JPEG XL preview from a TIFF/DNG-based RAW.
 *
 * The TIFF IFDs (IFD0, its SubIFDs and the NextIFD chain) are walked in pure
 * PHP. An IFD is a JXL preview candidate when it is JXL-compressed
 * (`Compression` 52546, or legacy 50002), flagged as a reduced-resolution image
 * (`NewSubfileType` bit 0), has a viewable photometric interpretation (RGB,
 * YCbCr or BlackIsZero) and carries in-bounds strip or tile data within the
 * size/pixel limits.
 *
 * The JXL preview only wins when it is strictly larger than the best embedded
 * JPEG, so a conversion is never paid for a smaller or equal preview. A failed
 * conversion returns null and lets the existing JPEG/Imagick/libraw fallbacks
 * run. The converted JPEG is oriented here (preview IFD orientation, else
 * IFD0), which is why {@see appliesOrientation()} returns true.
 */
class JxlPreviewExtractor implements FormatExtractor
{
    public const METHOD_NAME = 'embedded JPEG XL';

    private const TAG_NEW_SUBFILE_TYPE = 0x00FE;
    private const TAG_IMAGE_WIDTH = 0x0100;
    private const TAG_IMAGE_LENGTH = 0x0101;
    private const TAG_COMPRESSION = 0x0103;
    private const TAG_PHOTOMETRIC = 0x0106;
    private const TAG_ORIENTATION = 0x0112;
    private const TAG_STRIP_OFFSETS = 0x0111;
    private const TAG_STRIP_BYTE_COUNTS = 0x0117;
    private const TAG_TILE_OFFSETS = 0x0144;
    private const TAG_TILE_BYTE_COUNTS = 0x0145;
    private const TAG_SUB_IFDS = 0x014A;

    /**
     * Tags the parser actually consumes. Every other IFD entry is skipped
     * before its values are materialised, so a crafted file cannot make PHP
     * allocate large arrays for unused tags.
     */
    private const NEEDED_TAGS = [
        self::TAG_NEW_SUBFILE_TYPE => true,
        self::TAG_IMAGE_WIDTH => true,
        self::TAG_IMAGE_LENGTH => true,
        self::TAG_COMPRESSION => true,
        self::TAG_PHOTOMETRIC => true,
        self::TAG_ORIENTATION => true,
        self::TAG_STRIP_OFFSETS => true,
        self::TAG_STRIP_BYTE_COUNTS => true,
        self::TAG_TILE_OFFSETS => true,
        self::TAG_TILE_BYTE_COUNTS => true,
        self::TAG_SUB_IFDS => true,
    ];

    /**
     * Tags whose values are consumed as a single integer. For these only the
     * first value is read, so a crafted entry with a huge count cannot make the
     * parser materialise an array it will never look at.
     */
    private const SCALAR_TAGS = [
        self::TAG_NEW_SUBFILE_TYPE => true,
        self::TAG_IMAGE_WIDTH => true,
        self::TAG_IMAGE_LENGTH => true,
        self::TAG_COMPRESSION => true,
        self::TAG_PHOTOMETRIC => true,
        self::TAG_ORIENTATION => true,
    ];

    private const TYPE_BYTE = 1;
    private const TYPE_SHORT = 3;
    private const TYPE_LONG = 4;
    private const TYPE_IFD = 13;

    private const COMPRESSION_JXL_DNG = 52546;
    private const COMPRESSION_JXL_LEGACY = 50002;

    private const PHOTOMETRIC_BLACK_IS_ZERO = 1;
    private const PHOTOMETRIC_RGB = 2;
    private const PHOTOMETRIC_YCBCR = 6;

    private const MAX_PREVIEW_BYTES = 64 * 1024 * 1024;
    private const MAX_PIXELS = 100_000_000;
    private const MAX_IFD_ENTRIES = 4096;
    private const MAX_IFD_DEPTH = 8;
    /**
     * Total IFDs visited in one parse. Depth and the visited set stop cycles,
     * but not breadth: a file can cheaply describe a huge SubIFD tree. Real
     * DNGs have a handful of IFDs, so this is generous.
     */
    private const MAX_IFDS = 512;
    /** Array-valued tags (strips, tiles, SubIFDs) per IFD. */
    private const MAX_TAG_VALUES = 4096;
    private const MAX_SEGMENTS = 4096;

    private bool $little = true;
    /** @var int IFDs visited during the current parse() call. */
    private int $ifdCount = 0;

    public function supports(FileReader $reader): bool
    {
        $magic = $reader->readAt(0, 4);
        return $magic === "II\x2A\x00" || $magic === "MM\x00\x2A";
    }

    public function name(): string
    {
        return self::METHOD_NAME;
    }

    /**
     * The converted JPEG is already upright (see extract()), so the façade must
     * not run its own orientation pass on top of it.
     */
    public function appliesOrientation(): bool
    {
        return true;
    }

    public function extract(FileReader $reader): ?string
    {
        $parsed = $this->parse($reader);
        $best = $parsed['best'];
        if ($best === null) {
            return null;
        }

        // Equal or smaller than the best embedded JPEG: keep the JPEG and do
        // not pay for a conversion.
        if ($best['area'] <= $this->bestJpegArea($reader)) {
            return null;
        }

        $jxl = $this->readSegments($reader, $best['segments']);
        if ($jxl === null) {
            return null;
        }

        $jpeg = JxlConverter::convert($jxl);
        if ($jpeg === null) {
            return null;
        }

        return JpegOrienter::makeUpright(
            $jpeg,
            $best['orientation'] ?? $parsed['ifd0Orientation']
        );
    }

    /**
     * Pixel area of the best decodable embedded JPEG, or 0 when there is none.
     */
    private function bestJpegArea(FileReader $reader): int
    {
        $jpeg = (new EmbeddedJpegExtractor())->extract($reader);
        if ($jpeg === null) {
            return 0;
        }
        $resolution = JpegValidator::getResolution($jpeg);
        return $resolution === null ? 0 : $resolution[0] * $resolution[1];
    }

    /**
     * Read and concatenate a candidate's strips/tiles in order.
     *
     * @param array<int, array{0:int,1:int}> $segments
     */
    private function readSegments(FileReader $reader, array $segments): ?string
    {
        $data = '';
        foreach ($segments as [$offset, $count]) {
            $chunk = $reader->readBytes($offset, $count);
            if ($chunk === null) {
                return null;
            }
            $data .= $chunk;
        }
        return $data !== '' ? $data : null;
    }

    /**
     * @return array{best: array|null, ifd0Orientation: int|null}
     */
    private function parse(FileReader $reader): array
    {
        $empty = ['best' => null, 'ifd0Orientation' => null];

        $header = $reader->readAt(0, 8);
        if ($header === null || strlen($header) < 8) {
            return $empty;
        }

        $byteOrder = substr($header, 0, 2);
        if ($byteOrder === 'II') {
            $this->little = true;
        } elseif ($byteOrder === 'MM') {
            $this->little = false;
        } else {
            return $empty;
        }

        if ($this->u16(substr($header, 2, 2), $this->little, 0) !== 42) {
            return $empty;
        }

        $ifd0Offset = $this->u32(substr($header, 4, 4), $this->little, 0);
        if ($ifd0Offset < 8 || $ifd0Offset > $reader->size()) {
            return $empty;
        }

        $best = null;
        $ifd0Orientation = null;
        $visited = [];
        $this->ifdCount = 0;
        $this->walkIfd($reader, $ifd0Offset, true, 0, $visited, $best, $ifd0Orientation);

        return ['best' => $best, 'ifd0Orientation' => $ifd0Orientation];
    }

    /**
     * Walk one IFD, collect a candidate if it is one, then recurse into its
     * SubIFDs and the NextIFD chain. Malformed offsets simply end that branch.
     *
     * @param array<int, bool> $visited
     * @param array|null $best Largest candidate seen so far (ties keep the first).
     */
    private function walkIfd(
        FileReader $reader,
        int $offset,
        bool $isIfd0,
        int $depth,
        array &$visited,
        ?array &$best,
        ?int &$ifd0Orientation
    ): void {
        if ($depth > self::MAX_IFD_DEPTH || $offset < 8 || isset($visited[$offset])) {
            return;
        }
        if ($this->ifdCount >= self::MAX_IFDS) {
            return; // global breadth guard: stop walking a crafted SubIFD tree
        }
        $fileSize = $reader->size();
        if ($offset > $fileSize - 6) {
            return;
        }

        $visited[$offset] = true;
        $this->ifdCount++;

        $countBytes = $reader->readBytes($offset, 2);
        if ($countBytes === null) {
            return;
        }
        $count = $this->u16($countBytes, $this->little);
        if ($count <= 0 || $count > self::MAX_IFD_ENTRIES) {
            return;
        }

        $entriesSize = $count * 12;
        if ($offset + 2 + $entriesSize + 4 > $fileSize) {
            return;
        }

        $entries = $reader->readBytes($offset + 2, $entriesSize);
        if ($entries === null) {
            return;
        }

        $tags = [];
        for ($i = 0; $i < $count; $i++) {
            $entry = substr($entries, $i * 12, 12);
            $tag = $this->u16(substr($entry, 0, 2), $this->little);
            if (!isset(self::NEEDED_TAGS[$tag])) {
                continue; // unused tag: do not materialise its values
            }
            if (isset($tags[$tag])) {
                continue; // first occurrence wins
            }
            $type = $this->u16(substr($entry, 2, 2), $this->little);
            $valueCount = $this->u32(substr($entry, 4, 4), $this->little);
            $valueField = substr($entry, 8, 4);
            $values = $this->readTagValues(
                $reader,
                $type,
                $valueCount,
                $valueField,
                isset(self::SCALAR_TAGS[$tag])
            );
            if ($values !== null) {
                $tags[$tag] = $values;
            }
        }

        if ($isIfd0) {
            $ifd0Orientation = $this->orientation($tags);
        }

        $candidate = $this->candidate($reader, $tags);
        if ($candidate !== null && ($best === null || $candidate['area'] > $best['area'])) {
            $best = $candidate;
        }

        foreach ($tags[self::TAG_SUB_IFDS] ?? [] as $subOffset) {
            if (is_int($subOffset)) {
                $this->walkIfd($reader, $subOffset, false, $depth + 1, $visited, $best, $ifd0Orientation);
            }
        }

        $nextBytes = $reader->readBytes($offset + 2 + $entriesSize, 4);
        if ($nextBytes !== null) {
            $next = $this->u32($nextBytes, $this->little);
            if ($next > 0) {
                $this->walkIfd($reader, $next, false, $depth + 1, $visited, $best, $ifd0Orientation);
            }
        }
    }

    /**
     * Turn an IFD's tag map into a candidate descriptor, or null if it is not
     * a viewable JXL preview within the safety limits.
     *
     * @param array<int, array> $tags
     * @return array{area:int, segments:array<int, array{0:int,1:int}>, orientation:int|null}|null
     */
    private function candidate(FileReader $reader, array $tags): ?array
    {
        $compression = $this->firstInt($tags[self::TAG_COMPRESSION] ?? []);
        if ($compression !== self::COMPRESSION_JXL_DNG && $compression !== self::COMPRESSION_JXL_LEGACY) {
            return null;
        }

        $newSubfileType = $this->firstInt($tags[self::TAG_NEW_SUBFILE_TYPE] ?? []);
        if ($newSubfileType === null || ($newSubfileType & 1) === 0) {
            return null; // primary/raw IFD
        }

        $photometric = $this->firstInt($tags[self::TAG_PHOTOMETRIC] ?? []);
        if (!in_array($photometric, [
            self::PHOTOMETRIC_BLACK_IS_ZERO,
            self::PHOTOMETRIC_RGB,
            self::PHOTOMETRIC_YCBCR,
        ], true)) {
            return null;
        }

        $width = $this->firstInt($tags[self::TAG_IMAGE_WIDTH] ?? []);
        $height = $this->firstInt($tags[self::TAG_IMAGE_LENGTH] ?? []);
        if ($width === null || $height === null || $width <= 0 || $height <= 0) {
            return null;
        }
        // intdiv keeps the check exact and avoids overflowing the product.
        if (intdiv(self::MAX_PIXELS, $width) < $height) {
            return null;
        }

        $segments = $this->segments($reader, $tags);
        if ($segments === null) {
            return null;
        }

        return [
            'area' => $width * $height,
            'segments' => $segments,
            'orientation' => $this->orientation($tags),
        ];
    }

    /**
     * Validate and return the candidate's strips/tiles as [offset, count] pairs.
     *
     * @param array<int, array> $tags
     * @return array<int, array{0:int,1:int}>|null
     */
    private function segments(FileReader $reader, array $tags): ?array
    {
        if (isset($tags[self::TAG_TILE_OFFSETS], $tags[self::TAG_TILE_BYTE_COUNTS])) {
            $offsets = $tags[self::TAG_TILE_OFFSETS];
            $counts = $tags[self::TAG_TILE_BYTE_COUNTS];
        } elseif (isset($tags[self::TAG_STRIP_OFFSETS], $tags[self::TAG_STRIP_BYTE_COUNTS])) {
            $offsets = $tags[self::TAG_STRIP_OFFSETS];
            $counts = $tags[self::TAG_STRIP_BYTE_COUNTS];
        } else {
            return null;
        }

        if (count($offsets) === 0 || count($offsets) !== count($counts)) {
            return null;
        }
        if (count($offsets) > self::MAX_SEGMENTS) {
            return null;
        }

        $fileSize = $reader->size();
        $segments = [];
        $total = 0;

        foreach ($offsets as $i => $offset) {
            $count = $counts[$i] ?? null;
            if (!is_int($offset) || !is_int($count)) {
                return null;
            }
            if ($offset < 0 || $offset > $fileSize) {
                return null;
            }
            if ($count <= 0 || $count > $fileSize - $offset) {
                return null;
            }
            $total += $count;
            if ($total > self::MAX_PREVIEW_BYTES) {
                return null;
            }
            $segments[] = [$offset, $count];
        }

        return $total > 0 ? $segments : null;
    }

    /**
     * Read a tag's integer values, honouring inline vs out-of-line storage.
     *
     * Scalar tags only return their first value. The inline/out-of-line
     * decision still uses the declared count, because a count of 2+ shifts the
     * value out of the entry regardless of how many values we want.
     *
     * @return int[]|null
     */
    private function readTagValues(
        FileReader $reader,
        int $type,
        int $count,
        string $valueField,
        bool $scalar = false
    ): ?array {
        $size = match ($type) {
            self::TYPE_BYTE => 1,
            self::TYPE_SHORT => 2,
            self::TYPE_LONG, self::TYPE_IFD => 4,
            default => null,
        };
        if ($size === null || $count <= 0) {
            return null;
        }
        if (!$scalar && $count > self::MAX_TAG_VALUES) {
            return null;
        }

        $readCount = $scalar ? 1 : $count;
        // Inline storage depends on the declared count, not on how many values
        // we intend to read. The comparison also avoids multiplying a hostile
        // 32-bit count into a huge value.
        if ($count <= intdiv(4, $size)) {
            $bytes = $valueField;
        } else {
            $offset = $this->u32($valueField, $this->little);
            // Read only what is consumed; a scalar tag never needs the tail.
            $bytes = $reader->readBytes($offset, $readCount * $size);
            if ($bytes === null) {
                return null;
            }
        }

        $values = [];
        for ($i = 0; $i < $readCount; $i++) {
            $chunk = substr($bytes, $i * $size, $size);
            $values[] = match ($type) {
                self::TYPE_BYTE => ord($chunk),
                self::TYPE_SHORT => $this->u16($chunk, $this->little),
                default => $this->u32($chunk, $this->little),
            };
        }
        return $values;
    }

    /**
     * @param array<int, array> $tags
     */
    private function orientation(array $tags): ?int
    {
        $value = $this->firstInt($tags[self::TAG_ORIENTATION] ?? []);
        return ($value !== null && $value >= 1 && $value <= 8) ? $value : null;
    }

    /**
     * @param array<int, int> $values
     */
    private function firstInt(array $values): ?int
    {
        return isset($values[0]) && is_int($values[0]) ? $values[0] : null;
    }

    private function u16(string $bytes, bool $little, int $offset = 0): int
    {
        return $little
            ? (ord($bytes[$offset]) | (ord($bytes[$offset + 1]) << 8))
            : ((ord($bytes[$offset]) << 8) | ord($bytes[$offset + 1]));
    }

    private function u32(string $bytes, bool $little, int $offset = 0): int
    {
        return $little
            ? (ord($bytes[$offset])
                | (ord($bytes[$offset + 1]) << 8)
                | (ord($bytes[$offset + 2]) << 16)
                | (ord($bytes[$offset + 3]) << 24))
            : ((ord($bytes[$offset]) << 24)
                | (ord($bytes[$offset + 1]) << 16)
                | (ord($bytes[$offset + 2]) << 8)
                | ord($bytes[$offset + 3]));
    }
}
