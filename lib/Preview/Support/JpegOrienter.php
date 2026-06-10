<?php

namespace OCA\CameraRawPreviews\Preview\Support;

/**
 * Bakes an EXIF orientation into JPEG bytes with GD so the result is upright
 * and carries no orientation tag of its own.
 *
 * Two orientation sources are consulted, in order: the preview JPEG's own EXIF
 * Orientation (e.g. Fujifilm RAF previews carry it), then the orientation
 * passed in by the caller (typically the TIFF container's IFD0 tag, which is
 * where most camera RAWs keep it). The first one found wins; a value of 1 (or
 * anything outside 1-8) means "already upright" and the bytes pass through
 * untouched, so the common landscape case never pays for a re-encode.
 */
class JpegOrienter
{
    /**
     * Return JPEG bytes rotated/flipped to be upright.
     *
     * @param string $jpeg JPEG data to normalise.
     * @param int|null $containerOrientation Fallback orientation (1-8) from the
     *                 surrounding container when the preview has none of its own.
     * @return string Upright JPEG bytes (the input unchanged if no work needed
     *                or if GD cannot decode it).
     */
    public static function makeUpright(string $jpeg, ?int $containerOrientation): string
    {
        $orientation = self::exifOrientation($jpeg) ?? $containerOrientation ?? 1;
        if ($orientation <= 1 || $orientation > 8) {
            return $jpeg;
        }
        return self::applyWithGd($jpeg, $orientation) ?? $jpeg;
    }

    /**
     * Read the EXIF Orientation the JPEG carries itself, if any.
     *
     * @return int|null Orientation 1-8, or null when unavailable/unparseable.
     */
    private static function exifOrientation(string $jpeg): ?int
    {
        if (!function_exists('exif_read_data')) {
            return null;
        }
        $stream = fopen('php://memory', 'r+b');
        if ($stream === false) {
            return null;
        }
        try {
            fwrite($stream, $jpeg);
            rewind($stream);
            $exif = @exif_read_data($stream);
        } finally {
            fclose($stream);
        }
        if (is_array($exif) && isset($exif['Orientation'])) {
            $value = (int)$exif['Orientation'];
            return ($value >= 1 && $value <= 8) ? $value : null;
        }
        return null;
    }

    /**
     * Apply an EXIF orientation with GD and re-encode. The re-encoded JPEG
     * carries no orientation tag, so any later fixOrientation() is a no-op.
     *
     * @param int $orientation EXIF orientation 2-8 (1 is filtered out by caller).
     * @return string|null Re-encoded bytes, or null if GD cannot decode the input.
     */
    private static function applyWithGd(string $jpeg, int $orientation): ?string
    {
        $image = @imagecreatefromstring($jpeg);
        if ($image === false) {
            return null;
        }

        // GD's imagerotate turns counter-clockwise, so negative angles are CW.
        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $image = imagerotate($image, 180, 0);
                break;
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);
                break;
            case 5:
                $image = imagerotate($image, -90, 0);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 6:
                $image = imagerotate($image, -90, 0);
                break;
            case 7:
                $image = imagerotate($image, 90, 0);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 8:
                $image = imagerotate($image, 90, 0);
                break;
            default:
                return null; // 1 or unknown: nothing to do
        }

        if ($image === false) {
            return null;
        }

        ob_start();
        $ok = imagejpeg($image, null, 95);
        $blob = ob_get_clean();
        return ($ok && $blob !== '' && $blob !== false) ? $blob : null;
    }
}
