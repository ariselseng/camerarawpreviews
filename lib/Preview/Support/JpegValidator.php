<?php

namespace OCA\CameraRawPreviews\Preview\Support;

/**
 * GD-backed JPEG validation helpers shared by the format extractors.
 */
class JpegValidator
{
    /**
     * Largest image handed to GD. Real full-size embedded previews are around
     * 45-50 MP; 64 MP leaves room for those while stopping a crafted header
     * from claiming 65535x65535 pixels (~16 TB of GD bitmap).
     */
    private const MAX_DECODE_PIXELS = 64_000_000;
    /** GD truecolour images need four bytes per pixel. */
    private const GD_BYTES_PER_PIXEL = 4;

    /**
     * Read a JPEG's pixel dimensions from its header.
     *
     * @param string $data Raw JPEG bytes.
     * @return array{0:int,1:int}|null [width, height], or null if not a JPEG.
     */
    public static function getResolution(string $data): ?array
    {
        $info = @getimagesizefromstring($data);
        if ($info === false || $info[0] <= 0 || $info[1] <= 0) {
            return null;
        }
        if (isset($info[2]) && $info[2] !== IMAGETYPE_JPEG) {
            return null;
        }
        // Reject single-channel (grayscale) JPEGs — these are raw sensor data
        // (e.g. Canon CRW Bayer frames), not viewable previews.
        if (isset($info['channels']) && $info['channels'] === 1) {
            return null;
        }
        return [$info[0], $info[1]];
    }

    /**
     * Whether GD can plausibly decode an image of this size without exceeding
     * the PHP memory limit. Header-only check: no image data is decoded.
     */
    public static function canDecode(int $width, int $height): bool
    {
        if ($width <= 0 || $height <= 0) {
            return false;
        }
        // intdiv keeps the comparison exact and avoids overflowing the product.
        if ($width > intdiv(self::MAX_DECODE_PIXELS, $height)) {
            return false;
        }

        $pixels = $width * $height;
        $limit = self::memoryLimitBytes();
        if ($limit === null) {
            return true; // no limit configured
        }

        $available = $limit - memory_get_usage(true);
        return $available > 0 && $pixels <= intdiv($available, self::GD_BYTES_PER_PIXEL);
    }

    /**
     * The configured memory_limit in bytes, or null when unlimited/unknown.
     */
    private static function memoryLimitBytes(): ?int
    {
        $raw = trim((string)ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $value = (int)$raw;
        if ($value <= 0) {
            return null;
        }

        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Verify the data fully decodes as an image via GD.
     */
    public static function isDecodable(string $data): bool
    {
        // imagecreatefromstring returns false on truncated/corrupt data.
        // Note: imagedestroy() is intentionally omitted — it has been a no-op
        // since PHP 8.0 (the GC frees the image) and is deprecated in 8.5.
        return @imagecreatefromstring($data) !== false;
    }
}
