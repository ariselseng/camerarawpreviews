<?php

namespace OCA\CameraRawPreviews\Preview\Support;

/**
 * Reads the EXIF/TIFF Orientation tag (0x0112) from the first IFD of a
 * TIFF-based file, in pure PHP.
 *
 * Camera RAW files are TIFF containers, and many embed a full-size JPEG
 * preview that carries no orientation of its own — the orientation lives in the
 * container's IFD0. This lets us rotate such previews upright without shelling
 * out to exiftool. Non-TIFF inputs (e.g. Fujifilm RAF) return null; their
 * previews carry their own EXIF orientation instead.
 */
class OrientationReader
{
    private const ORIENTATION_TAG = 0x0112;
    private const MAX_IFD_ENTRIES = 1000; // sanity guard against bogus headers

    /**
     * @return int|null Orientation value 1-8, or null if not present/parseable.
     */
    public static function read(string $path): ?int
    {
        $reader = FileReader::open($path);
        if ($reader === null) {
            return null;
        }
        try {
            return self::readFrom($reader);
        } finally {
            $reader->close();
        }
    }

    public static function readFrom(FileReader $reader): ?int
    {
        $header = $reader->readAt(0, 14);
        if ($header === null || strlen($header) < 8) {
            return null;
        }

        // CRW (Canon CIFF) — identified by "HEAPCCDR" at offset 6.
        // Not TIFF-based; rotation lives in the CIFF ImageInfo record.
        if (strlen($header) >= 14 && substr($header, 6, 8) === 'HEAPCCDR') {
            return self::readCrw($reader);
        }

        $byteOrder = substr($header, 0, 2);
        if ($byteOrder === 'II') {
            $little = true;
        } elseif ($byteOrder === 'MM') {
            $little = false;
        } else {
            return null;
        }

        // Magic number 42 confirms a TIFF header.
        if (self::u16(substr($header, 2, 2), $little) !== 42) {
            return null;
        }

        $ifdOffset = self::u32(substr($header, 4, 4), $little);
        if ($ifdOffset < 8) {
            return null;
        }

        $countBytes = $reader->readAt($ifdOffset, 2);
        if ($countBytes === null || strlen($countBytes) < 2) {
            return null;
        }
        $count = self::u16($countBytes, $little);
        if ($count <= 0 || $count > self::MAX_IFD_ENTRIES) {
            return null;
        }

        $entries = $reader->readAt($ifdOffset + 2, $count * 12);
        if ($entries === null || strlen($entries) < $count * 12) {
            return null;
        }

        for ($i = 0; $i < $count; $i++) {
            $entry = substr($entries, $i * 12, 12);
            if (self::u16(substr($entry, 0, 2), $little) !== self::ORIENTATION_TAG) {
                continue;
            }
            // Orientation is a SHORT; its value sits in the first 2 bytes of the
            // 4-byte value/offset field, in the file's byte order.
            $value = self::u16(substr($entry, 8, 2), $little);
            return ($value >= 1 && $value <= 8) ? $value : null;
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // CRW / CIFF support
    // -------------------------------------------------------------------------

    /**
     * Read the orientation from a Canon CRW (CIFF) file.
     *
     * CRW uses Canon's CIFF format — a hierarchical heap, NOT a TIFF container.
     * Orientation is stored as a rotation-in-degrees value at byte offset 12
     * within the ImageInfo binary record (tag 0x1810), which lives inside the
     * ImageProps subdirectory (tag 0x300a) of the root heap.
     *
     * CIFF heap structure (recursive):
     *   - A heap block is a flat byte array.
     *   - Its last 4 bytes are the offset (from the start of THIS block) to the
     *     directory that lists the records within the block.
     *   - Each directory entry: uint16 typeCode | uint32 dataSize | uint32 offset
     *     where offset is from the start of the enclosing heap block.
     *   - A record whose typeCode has bits [15:14] = 0b11 is itself a heap block
     *     (subdirectory); recurse with the record's data as the new heap block.
     *
     * Root heap starts at heapStart (bytes 2–5 of the file header), and its
     * last-4-bytes pointer is at the very end of the file.
     */
    private static function readCrw(FileReader $reader): ?int
    {
        $header = $reader->readAt(0, 6);
        if ($header === null || strlen($header) < 6) {
            return null;
        }
        $little    = (substr($header, 0, 2) === 'II');
        $heapStart = self::u32(substr($header, 2, 4), $little); // typically 26

        $fileSize = $reader->size();

        // Root heap: last 4 bytes of file = local offset (from heapStart) to dir.
        $b = $reader->readAt($fileSize - 4, 4);
        if ($b === null || strlen($b) < 4) {
            return null;
        }
        $rootDirLocalOffset = self::u32($b, $little);
        $rootDirAbs         = $heapStart + $rootDirLocalOffset;

        // Find tag 0x300a (ImageProps) in the root directory.
        // Root entries have offsets from $heapStart.
        $entry = self::ciffFindEntry($reader, $rootDirAbs, $little, 0x300a);
        if ($entry === null) {
            return null;
        }
        [$ipsLocalOff, $ipsSize] = $entry;
        $ipsBase = $heapStart + $ipsLocalOff; // absolute start of ImageProps block

        // ImageProps is a sub-heap; its last 4 bytes = local offset to its dir.
        $b = $reader->readAt($ipsBase + $ipsSize - 4, 4);
        if ($b === null || strlen($b) < 4) {
            return null;
        }
        $ipsDirLocalOffset = self::u32($b, $little);
        $ipsDirAbs         = $ipsBase + $ipsDirLocalOffset;

        // Find tag 0x1810 (ImageInfo) in the ImageProps directory.
        // ImageProps entries have offsets from $ipsBase.
        $entry = self::ciffFindEntry($reader, $ipsDirAbs, $little, 0x1810);
        if ($entry === null) {
            return null;
        }
        [$iiLocalOff] = $entry;
        $iiAbs = $ipsBase + $iiLocalOff; // absolute start of ImageInfo binary block

        // ImageInfo layout (int32 × 7, little-endian):
        //   [0] ImageWidth  [1] ImageHeight  [2] PixelAspectRatio (float)
        //   [3] Rotation (degrees: 0 / 90 / 180 / 270)  [4–6] …
        $rotBytes = $reader->readAt($iiAbs + 12, 4);
        if ($rotBytes === null || strlen($rotBytes) < 4) {
            return null;
        }
        $degrees = self::u32($rotBytes, $little);

        return match ($degrees) {
            0   => 1,  // normal
            90  => 6,  // 90° CW rotation needed to display upright
            180 => 3,  // 180° rotation needed
            270 => 8,  // 90° CCW (= 270° CW) rotation needed
            default => null,
        };
    }

    /**
     * Walk a CIFF directory and return [localOffset, dataSize] for the first
     * entry matching $targetTag, or null if not found.
     *
     * @param int $dirAbs Absolute file offset of the directory (starts with uint16 count).
     * @return array{int,int}|null
     */
    private static function ciffFindEntry(
        FileReader $reader,
        int $dirAbs,
        bool $little,
        int $targetTag
    ): ?array {
        $countBytes = $reader->readAt($dirAbs, 2);
        if ($countBytes === null || strlen($countBytes) < 2) {
            return null;
        }
        $count = self::u16($countBytes, $little);
        if ($count <= 0 || $count > 500) {
            return null; // sanity guard
        }

        $entries = $reader->readAt($dirAbs + 2, $count * 10);
        if ($entries === null || strlen($entries) < $count * 10) {
            return null;
        }

        for ($i = 0; $i < $count; $i++) {
            $e   = substr($entries, $i * 10, 10);
            $tag = self::u16(substr($e, 0, 2), $little);
            if ($tag === $targetTag) {
                $size   = self::u32(substr($e, 2, 4), $little);
                $offset = self::u32(substr($e, 6, 4), $little);
                return [$offset, $size];
            }
        }

        return null;
    }

    private static function u16(string $bytes, bool $little): int
    {
        return $little
            ? (ord($bytes[0]) | (ord($bytes[1]) << 8))
            : ((ord($bytes[0]) << 8) | ord($bytes[1]));
    }

    private static function u32(string $bytes, bool $little): int
    {
        return $little
            ? (ord($bytes[0]) | (ord($bytes[1]) << 8) | (ord($bytes[2]) << 16) | (ord($bytes[3]) << 24))
            : ((ord($bytes[0]) << 24) | (ord($bytes[1]) << 16) | (ord($bytes[2]) << 8) | ord($bytes[3]));
    }
}
