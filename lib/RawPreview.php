<?php


namespace OCA\CameraRawPreviews;

use OCP\Preview\IProvider;

class RawPreview implements IProvider {
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
                $converter = \OC_Helper::findBinaryPath('exiftool');
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

                
                $im->setImageFormat('jpg');
                $im->setImageCompressionQuality(90);
                //new bitmap image object
                $image = new \OC_Image($im);
                // $image->loadFromData($im);
                //check if image object is valid
                return $image->valid() ? $image : false;
        }

        protected function getResizedPreview($tmpPath, $maxX, $maxY) {
            $converter = \OC_Helper::findBinaryPath('exiftool');
            $im = new \Imagick();
            $im->readImageBlob(shell_exec($converter . " -b -PreviewImage " . escapeshellarg($tmpPath)));
            if (!$im->valid()) {
                return false;
            }
            $im = $this->resize($im, $maxX, $maxY);
            unlink($tmpPath);
            return $im;
  	}
        protected function resize($im, $maxX, $maxY) {
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
