<?php

namespace OCA\CameraRawPreviews;

use OCA\CameraRawPreviews\Preview\EmbeddedJpegExtractor;
use OCA\CameraRawPreviews\Preview\FormatExtractor;
use OCA\CameraRawPreviews\Preview\InDesignExtractor;
use OCA\CameraRawPreviews\Preview\MrwExtractor;
use OCA\CameraRawPreviews\Preview\Support\FileReader;
use OCA\CameraRawPreviews\Preview\Support\JpegOrienter;
use OCA\CameraRawPreviews\Preview\Support\OrientationReader;
use OCA\CameraRawPreviews\Preview\Support\RawCliRenderer;
use OCA\CameraRawPreviews\Preview\TiffImagickExtractor;

// Allow standalone use (e.g. the extract-preview CLI) without an autoloader.
// Under Nextcloud these classes autoload; the guards prevent a double-declare.
if (!class_exists(FileReader::class, false)) {
    require_once __DIR__ . '/Preview/Support/FileReader.php';
}
if (!class_exists(\OCA\CameraRawPreviews\Preview\Support\JpegValidator::class, false)) {
    require_once __DIR__ . '/Preview/Support/JpegValidator.php';
}
if (!class_exists(OrientationReader::class, false)) {
    require_once __DIR__ . '/Preview/Support/OrientationReader.php';
}
if (!class_exists(JpegOrienter::class, false)) {
    require_once __DIR__ . '/Preview/Support/JpegOrienter.php';
}
if (!class_exists(RawCliRenderer::class, false)) {
    require_once __DIR__ . '/Preview/Support/RawCliRenderer.php';
}
if (!interface_exists(FormatExtractor::class, false)) {
    require_once __DIR__ . '/Preview/FormatExtractor.php';
}
if (!class_exists(InDesignExtractor::class, false)) {
    require_once __DIR__ . '/Preview/InDesignExtractor.php';
}
if (!class_exists(MrwExtractor::class, false)) {
    require_once __DIR__ . '/Preview/MrwExtractor.php';
}
if (!class_exists(EmbeddedJpegExtractor::class, false)) {
    require_once __DIR__ . '/Preview/EmbeddedJpegExtractor.php';
}
if (!class_exists(TiffImagickExtractor::class, false)) {
    require_once __DIR__ . '/Preview/TiffImagickExtractor.php';
}

/**
 * Façade that extracts a JPEG preview from a container file.
 *
 * It opens the file once and offers it to each registered {@see FormatExtractor}
 * in priority order, returning the first non-null result. Adding a new format
 * is a matter of writing a FormatExtractor and listing it in {@see extractors()}.
 *
 * No external command-line tools are used; the only optional dependency is
 * Imagick, needed solely by the TIFF fallback.
 */
class PreviewExtractor
{
    /**
     * Extract an embedded/rendered preview image as JPEG bytes.
     *
     * @param string $filePath Path to the source file.
     * The returned JPEG is always upright: unless the winning extractor already
     * orients its output, the preview's own EXIF orientation (or, failing that,
     * the container's TIFF orientation tag) is baked in before returning. Callers
     * therefore never need to rotate the result themselves.
     *
     * @param string|null $method Out-param: label of the extractor that
     *                            produced the result (e.g. "embedded JPEG").
     * @return string|null JPEG data, or null if no valid preview was found.
     */
    public static function extractPreview(string $filePath, ?string &$method = null): ?string
    {
        $method = null;

        $reader = FileReader::open($filePath);
        if ($reader === null) {
            return null;
        }

        try {
            foreach (self::extractors() as $extractor) {
                if (!$extractor->supports($reader)) {
                    continue;
                }
                $result = $extractor->extract($reader);
                if ($result !== null) {
                    $method = $extractor->name();
                    if (!$extractor->appliesOrientation()) {
                        $result = JpegOrienter::makeUpright($result, OrientationReader::readFrom($reader));
                    }
                    return $result;
                }
            }
        } finally {
            $reader->close();
        }

        // Last resort: hand the file to the optional libraw CLI. This only does
        // anything when an admin has installed the camerarawpreviews binary and
        // its path is configured; otherwise it returns null and we report no
        // preview, exactly as before. The CLI's JPEG is already upright, so no
        // orientation pass is applied.
        $rendered = RawCliRenderer::renderPreview($filePath);
        if ($rendered !== null) {
            $method = 'libraw cli';
            return $rendered;
        }

        return null;
    }

    /**
     * Registered extractors, in priority order.
     *
     * Order matters: InDesign and MRW are checked before the generic JPEG scan
     * because their previews are invisible to a raw FF-D8 scan (InDesign hides
     * it base64-encoded inside XMP; Minolta MRW clobbers the SOI marker byte).
     * The Imagick TIFF render is the last-resort fallback for TIFF-based files
     * that carry no decodable embedded JPEG.
     *
     * @return FormatExtractor[]
     */
    private static function extractors(): array
    {
        return [
            new InDesignExtractor(),
            new MrwExtractor(),
            new EmbeddedJpegExtractor(),
            new TiffImagickExtractor(),
        ];
    }
}
