<?php

namespace OCA\CameraRawPreviews\Preview;

use OCA\CameraRawPreviews\Preview\Support\FileReader;

/**
 * Renders TIFF-based files that carry no extractable embedded JPEG.
 *
 * This is the fallback for plain TIFFs and TIFF-based RAWs whose only image
 * data is non-JPEG (e.g. Hasselblad 3FR, which stores lossless SOF3 data GD
 * cannot decode). It is the one extractor that needs Imagick; if Imagick or
 * its TIFF delegate is unavailable, {@see supports()} returns false and the
 * file is reported as having no preview.
 */
class TiffImagickExtractor implements FormatExtractor
{
    /**
     * Method label for this extractor. Imagick auto-orients its render, so the
     * provider keys off this to skip its own orientation pass. Exposed as a
     * constant so the two stay in lock-step.
     */
    public const METHOD_NAME = 'Imagick TIFF render';

    /**
     * Bounds for rendering an untrusted TIFF. ImageMagick is the only native
     * parser we hand arbitrary TIFF bytes to, and its default limits are
     * deployment-wide, so clamp them around this call and restore afterwards.
     */
    private const MAX_DECODE_PIXELS = 64_000_000;
    private const MAX_OUTPUT_BYTES = 64 * 1024 * 1024;
    private const MEMORY_LIMIT = 128 * 1024 * 1024;
    private const MAP_LIMIT = 128 * 1024 * 1024;
    private const DISK_LIMIT = 128 * 1024 * 1024;
    private const TIME_LIMIT = 30;

    public function supports(FileReader $reader): bool
    {
        $magic = $reader->readAt(0, 2);
        if ($magic !== 'II' && $magic !== 'MM') {
            return false;
        }
        return extension_loaded('imagick') && count(\Imagick::queryFormats('TIFF')) > 0;
    }

    public function name(): string
    {
        return self::METHOD_NAME;
    }

    /**
     * Imagick's autoOrient() already rotates the render upright and resets the
     * orientation tag, so PreviewExtractor must not orient it a second time.
     */
    public function appliesOrientation(): bool
    {
        return true;
    }

    public function extract(FileReader $reader): ?string
    {
        $previousLimits = $this->applyResourceLimits();
        $imagick = null;

        try {
            $imagick = new \Imagick();
            $imagick->readImage($reader->path());

            // A TIFF can hold several frames — e.g. a usable full-colour preview
            // plus a tiny 16-bit reduced image. Imagick's default frame is not
            // necessarily the best one (on the Canon 1D .TIF it lands on a small
            // 16-bit frame that renders near-black), so pick the largest frame
            // by pixel area.
            $bestIndex = 0;
            $bestArea = -1;
            $count = $imagick->getNumberImages();
            for ($i = 0; $i < $count; $i++) {
                $imagick->setIteratorIndex($i);
                $area = $imagick->getImageWidth() * $imagick->getImageHeight();
                if ($area > $bestArea) {
                    $bestArea = $area;
                    $bestIndex = $i;
                }
            }
            $imagick->setIteratorIndex($bestIndex);

            $imagick->autoOrient();
            $imagick->setImageFormat('jpg');
            $blob = $imagick->getImageBlob();

            return ($blob !== '' && strlen($blob) <= self::MAX_OUTPUT_BYTES) ? $blob : null;
        } catch (\Throwable $e) {
            return null;
        } finally {
            if ($imagick !== null) {
                try {
                    $imagick->clear();
                } catch (\Throwable $e) {
                    // Nothing useful to do; the object is going out of scope.
                }
            }
            $this->restoreResourceLimits($previousLimits);
        }
    }

    /**
     * Clamp ImageMagick's process-wide limits for the duration of this call.
     *
     * @return array<int, int> Previous values, keyed by resource type.
     */
    private function applyResourceLimits(): array
    {
        if (!method_exists(\Imagick::class, 'setResourceLimit')) {
            return [];
        }

        $wanted = [
            'RESOURCETYPE_MEMORY' => self::MEMORY_LIMIT,
            'RESOURCETYPE_MAP' => self::MAP_LIMIT,
            'RESOURCETYPE_DISK' => self::DISK_LIMIT,
            'RESOURCETYPE_AREA' => self::MAX_DECODE_PIXELS,
            'RESOURCETYPE_TIME' => self::TIME_LIMIT,
        ];

        $previous = [];
        foreach ($wanted as $name => $value) {
            $constant = 'Imagick::' . $name;
            if (!defined($constant)) {
                continue; // older Imagick builds may not expose every type
            }
            $type = constant($constant);
            try {
                $previous[$type] = \Imagick::getResourceLimit($type);
                \Imagick::setResourceLimit($type, $value);
            } catch (\Throwable $e) {
                unset($previous[$type]);
            }
        }

        return $previous;
    }

    /**
     * @param array<int, int> $previous
     */
    private function restoreResourceLimits(array $previous): void
    {
        foreach ($previous as $type => $value) {
            try {
                \Imagick::setResourceLimit($type, $value);
            } catch (\Throwable $e) {
                // Best effort: the request is ending anyway.
            }
        }
    }
}
