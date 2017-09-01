<?php


namespace OCA\CameraRawPreviews;

use OCA\CameraRawPreviews\RawPreview;

class IndesignPreview extends RawPreview {
        /**
         * {@inheritDoc}
         */
        public function getMimeType() {
                return '/image\/x-indesign/';
        }

        protected function getResizedPreview($tmpPath, $maxX, $maxY) {
                $converter = \OC_Helper::findBinaryPath('exiftool');
                $im = new \Imagick();
                $im->readImageBlob(shell_exec($converter . " -b -PageImage " . escapeshellarg($tmpPath)));
                if (!$im->valid()) {
                    \OCP\Util::writeLog('core', 'Camera Raw Previews:readImageBlob failed ' . $e->getmessage(), \OCP\Util::ERROR);
                    return false;
                }
                $im = $this->resize($im, $maxX, $maxY);
                if (!$im->valid()) {
                    \OCP\Util::writeLog('core', 'Camera Raw Previews:resize failed ' . $e->getmessage(), \OCP\Util::ERROR);
                    return false;
                }
                unlink($tmpPath);
                return $im;
              }
   
}
