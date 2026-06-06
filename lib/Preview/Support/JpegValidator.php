<?php

namespace OCA\CameraRawPreviews\Preview\Support;

/**
 * GD-backed JPEG validation helpers shared by the format extractors.
 */
class JpegValidator
{
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
        return [$info[0], $info[1]];
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
