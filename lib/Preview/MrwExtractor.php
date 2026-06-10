<?php

namespace OCA\CameraRawPreviews\Preview;

use OCA\CameraRawPreviews\Preview\Support\FileReader;
use OCA\CameraRawPreviews\Preview\Support\JpegValidator;

/**
 * Extracts the embedded preview JPEG from a Minolta RAW (.MRW) file.
 *
 * MRW files are a Minolta container (magic "\0MRM") wrapping several blocks; the
 * "\0TTW" block holds a standard TIFF structure with the EXIF/MakerNote data.
 * The preview JPEG is referenced by two Minolta MakerNote tags:
 *   0x0088 PreviewImageStart  (offset, relative to the TTW TIFF base)
 *   0x0089 PreviewImageLength (byte length)
 *
 * The catch — and the reason a generic FF-D8 scan fails on MRW — is that Minolta
 * stores the preview with the first byte of its SOI marker overwritten (with
 * 0x00 on the DiMAGE compacts, 0x02 on the Maxxum/Dynax DSLRs). So there is no
 * "\xFF\xD8" anywhere in the file. exiftool repairs this by forcing the first
 * byte back to 0xFF; we do exactly the same. The result is byte-identical to
 * `exiftool -b -PreviewImage`.
 *
 * Everything is pure PHP — no exiftool, no Imagick.
 */
class MrwExtractor implements FormatExtractor
{
    private const MAGIC = "\x00MRM";
    private const TTW_BLOCK = "\x00TTW";

    private const TAG_EXIF_IFD = 0x8769;       // standard TIFF: pointer to EXIF IFD
    private const TAG_MAKERNOTE = 0x927C;      // standard EXIF: MakerNote
    private const TAG_PREVIEW_START = 0x0088;  // Minolta MakerNote
    private const TAG_PREVIEW_LENGTH = 0x0089; // Minolta MakerNote

    private const MAX_IFD_ENTRIES = 1000; // sanity guard against bogus headers

    public function supports(FileReader $reader): bool
    {
        return $reader->readAt(0, 4) === self::MAGIC;
    }

    public function name(): string
    {
        return 'Minolta MRW preview';
    }

    /**
     * The repaired preview is returned verbatim; PreviewExtractor applies any
     * orientation the preview JPEG itself carries.
     */
    public function appliesOrientation(): bool
    {
        return false;
    }

    public function extract(FileReader $reader): ?string
    {
        $ttwBody = $this->findTtwBody($reader);
        if ($ttwBody === null) {
            return null;
        }

        // The TTW block is a self-contained TIFF; all its offsets are relative
        // to the block body, so we treat $ttwBody as the TIFF base.
        $header = $reader->readAt($ttwBody, 8);
        if ($header === null || strlen($header) < 8) {
            return null;
        }
        $byteOrder = substr($header, 0, 2);
        if ($byteOrder === 'II') {
            $little = true;
        } elseif ($byteOrder === 'MM') {
            $little = false;
        } else {
            return null;
        }
        if ($this->u16(substr($header, 2, 2), $little) !== 42) {
            return null;
        }
        $ifd0 = $this->u32(substr($header, 4, 4), $little);

        // IFD0 -> EXIF IFD -> MakerNote IFD -> Minolta preview tags.
        $ifd0Tags = $this->readIfd($reader, $ttwBody, $ifd0, $little);
        if ($ifd0Tags === null || !isset($ifd0Tags[self::TAG_EXIF_IFD])) {
            return null;
        }
        $exifTags = $this->readIfd($reader, $ttwBody, $ifd0Tags[self::TAG_EXIF_IFD], $little);
        if ($exifTags === null || !isset($exifTags[self::TAG_MAKERNOTE])) {
            return null;
        }
        // Minolta MakerNotes begin directly with an IFD (no vendor header).
        $makerTags = $this->readIfd($reader, $ttwBody, $exifTags[self::TAG_MAKERNOTE], $little);
        if ($makerTags === null
            || !isset($makerTags[self::TAG_PREVIEW_START])
            || !isset($makerTags[self::TAG_PREVIEW_LENGTH])) {
            return null;
        }

        $start = $ttwBody + $makerTags[self::TAG_PREVIEW_START];
        $length = $makerTags[self::TAG_PREVIEW_LENGTH];
        if ($length < 4 || $start < 0 || $start + $length > $reader->size()) {
            return null;
        }

        $jpeg = $reader->readBytes($start, $length);
        if ($jpeg === null) {
            return null;
        }

        // Repair the clobbered SOI: force the first byte back to 0xFF so the
        // blob becomes a well-formed JPEG (… the rest, "\xD8\xFF…", is intact).
        $jpeg[0] = "\xFF";

        if (JpegValidator::getResolution($jpeg) === null || !JpegValidator::isDecodable($jpeg)) {
            return null;
        }

        return $jpeg;
    }

    /**
     * Walk the top-level MRW blocks and return the absolute offset of the TTW
     * block's body, or null if absent/malformed.
     */
    private function findTtwBody(FileReader $reader): ?int
    {
        $head = $reader->readAt(4, 4);
        if ($head === null || strlen($head) < 4) {
            return null;
        }
        // Data-block length is always big-endian in the MRW container header.
        $dataLen = $this->u32($head, false);
        $end = 8 + $dataLen;
        $fileSize = $reader->size();
        if ($end > $fileSize) {
            $end = $fileSize;
        }

        $pos = 8;
        while ($pos + 8 <= $end) {
            $blockHeader = $reader->readAt($pos, 8);
            if ($blockHeader === null || strlen($blockHeader) < 8) {
                return null;
            }
            $tag = substr($blockHeader, 0, 4);
            $blockLen = $this->u32(substr($blockHeader, 4, 4), false);
            if ($tag === self::TTW_BLOCK) {
                return $pos + 8;
            }
            $pos += 8 + $blockLen;
        }

        return null;
    }

    /**
     * Read an IFD and return a map of tag => 32-bit value/offset field.
     *
     * Only the inline 4-byte value field is captured (sufficient for the
     * pointer and LONG tags we need); tags with out-of-line values still appear,
     * carrying their offset. Offsets are relative to $tiffBase.
     *
     * @return array<int,int>|null
     */
    private function readIfd(FileReader $reader, int $tiffBase, int $ifdOffset, bool $little): ?array
    {
        if ($ifdOffset < 8) {
            return null;
        }
        $absolute = $tiffBase + $ifdOffset;
        $countBytes = $reader->readAt($absolute, 2);
        if ($countBytes === null || strlen($countBytes) < 2) {
            return null;
        }
        $count = $this->u16($countBytes, $little);
        if ($count <= 0 || $count > self::MAX_IFD_ENTRIES) {
            return null;
        }

        $entries = $reader->readAt($absolute + 2, $count * 12);
        if ($entries === null || strlen($entries) < $count * 12) {
            return null;
        }

        $tags = [];
        for ($i = 0; $i < $count; $i++) {
            $entry = substr($entries, $i * 12, 12);
            $tag = $this->u16(substr($entry, 0, 2), $little);
            $tags[$tag] = $this->u32(substr($entry, 8, 4), $little);
        }

        return $tags;
    }

    private function u16(string $bytes, bool $little): int
    {
        return $little
            ? (ord($bytes[0]) | (ord($bytes[1]) << 8))
            : ((ord($bytes[0]) << 8) | ord($bytes[1]));
    }

    private function u32(string $bytes, bool $little): int
    {
        return $little
            ? (ord($bytes[0]) | (ord($bytes[1]) << 8) | (ord($bytes[2]) << 16) | (ord($bytes[3]) << 24))
            : ((ord($bytes[0]) << 24) | (ord($bytes[1]) << 16) | (ord($bytes[2]) << 8) | ord($bytes[3]));
    }
}
