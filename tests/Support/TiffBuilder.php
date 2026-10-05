<?php

namespace OCA\CameraRawPreviews\Tests\Support;

/**
 * Builds minimal TIFF streams for test fixtures.
 */
class TiffBuilder
{
    public const ORIENTATION_TAG = 0x0112;
    public const TYPE_SHORT = 3;

    /**
     * Build a minimal single-IFD TIFF.
     *
     * @param bool  $little  true for little-endian (II), false for big-endian (MM)
     * @param array $entries List of [tag, type, count, shortValue] tuples. Each
     *                       is encoded as a 12-byte IFD entry with the value
     *                       stored inline as a SHORT in the first 2 bytes.
     */
    public static function build(bool $little, array $entries): string
    {
        $u16 = fn (int $v): string => $little ? pack('v', $v) : pack('n', $v);
        $u32 = fn (int $v): string => $little ? pack('V', $v) : pack('N', $v);

        $header = ($little ? 'II' : 'MM') . $u16(42) . $u32(8);

        $ifd = $u16(count($entries));
        foreach ($entries as [$tag, $type, $count, $value]) {
            // 12-byte entry: tag(2) type(2) count(4) value/offset(4).
            // SHORT value sits in the first 2 bytes of the value field; the
            // remaining 2 bytes are padding (zero).
            $ifd .= $u16($tag) . $u16($type) . $u32($count) . $u16($value) . "\x00\x00";
        }
        $ifd .= $u32(0); // no next IFD

        return $header . $ifd;
    }

    /**
     * A little-endian TIFF whose IFD0 holds only an Orientation tag.
     */
    public static function withOrientation(int $orientation): string
    {
        return self::build(true, [[self::ORIENTATION_TAG, self::TYPE_SHORT, 1, $orientation]]);
    }
}
