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

   
}
