<?php

namespace OCA\CameraRawPreviews;

use OCP\Preview\IProvider;

class RawPreview implements IProvider {
    private $converter = \OC_Helper::findBinaryPath('exiftool');
    
    /**
     * {@inheritDoc}
     */
    public function getMimeType() {
        return '/image\/x-dcraw/';
    }

    /**
     * {@inheritDoc}
     */
    public function getThumbnail($path, $maxX, $maxY, $scalingup, $fileview) {
        if (empty($converter)) {
            return false;
        }
        $tmpPath = $fileview->toTmpFile($path);
        if (!$tmpPath) {
            return false;
        }

        // Creates \Imagick object from bitmap or vector file
        try {
            $im = $this->getResizedPreview($tmpPath, $maxX, $maxY);
        } catch (\Exception $e) {
            \OCP\Util::writeLog('core', 'Camera Raw Previews: ' . $e->getmessage(), \OCP\Util::ERROR);
            return false;
        }
        finally {
            unlink($tmpPath);
        }

        $im->setImageFormat('jpg');
        $im->setImageCompressionQuality(90);
        //new bitmap image object
        $image = new \OC_Image($im);
        //check if image object is valid
        return $image->valid() ? $image : false;
    }
        
    private function getResizedPreview($tmpPath, $maxX, $maxY) {
        $im = new \Imagick();
        $im->readImageBlob(shell_exec($converter . " -b -PreviewImage " . escapeshellarg($tmpPath)));

        if (!$im->valid()) {
            return false;
        }

        $this->rotateImageIfNeeded($im, $tmpPath);
        $this->resize($im, $maxX, $maxY);
        return $im;
    }

    private function rotateImageIfNeeded(\Imagick &$im, $path) {
        $rotate = 0;
        $flip = false;

        $rotation = shell_exec($converter . " -n -b -Orientation " . escapeshellarg($path));
        if ($rotation) {
            switch ($rotation) {
                case "2":
                    $rotate = 180;
                    $flip = true;
                    break;
                case "3":
                    $rotate = 180;
                    break;
                case "4":
                    $flip = true;
                    break;
                case "5":
                    $rotate = 270;
                    $flip = true;
                    break;
                case "6":
                    $rotate = 90;
                    break;
                case "7":
                    $rotate = 90;
                    $flip = true;
                    break;
                case "8":
                    $rotate = 270;
                    break;
            }
        }
        else {
            $rotate = shell_exec($converter . " -b -Rotation " . escapeshellarg($path));
        }
        if ($rotate) {
            $im->rotateImage(new \ImagickPixel(), $rotate);
        }
        if ($flip) {
            $im->flipImage();
        }
    }

    private function resize(\Imagick &$im, $maxX, $maxY) {
        list($previewWidth, $previewHeight) = array_values($im->getImageGeometry());

        if ($previewWidth > $maxX || $previewHeight > $maxY) {
            $im->resizeImage($maxX, $maxY, \imagick::FILTER_CATROM, 1, true);
        }

        return $im;
    }

    public function isAvailable(\OCP\Files\FileInfo $file) {
        return $file->getSize() > 0;
    }
}
