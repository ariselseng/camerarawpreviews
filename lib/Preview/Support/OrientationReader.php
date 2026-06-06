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
        $header = $reader->readAt(0, 8);
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
