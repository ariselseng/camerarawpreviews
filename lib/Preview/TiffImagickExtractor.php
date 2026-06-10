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
            $imagick->clear();
            return $blob !== '' ? $blob : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
