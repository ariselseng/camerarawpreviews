<?php

use OCP\AppFramework\App;
$app       = new App('camerarawpreviews');
$container = $app->getContainer();
if (extension_loaded('imagick') && !empty(\OC_Helper::findBinaryPath('exiftool'))) {
    $mimeTypeDetector = \OC::$server->getMimeTypeDetector();
    $mimeTypeLoader   = \OC::$server->getMimeTypeLoader();
    // Register custom mimetype we can hook in the frontend
    $mimeTypeDetector->getAllMappings();
    $mimeTypeDetector->registerType('indd', 'image/x-indesign', 'application/x-indesign');

    $previewManager = $container->getServer()->query('PreviewManager');

    $previewManager->registerProvider('/image\/x-dcraw/', function () {return new \OCA\CameraRawPreviews\RawPreview;});
    $previewManager->registerProvider('/image\/x-indesign/', function () {return new \OCA\CameraRawPreviews\IndesignPreview;});
} else {
    \OCP\Util::writeLog('core', 'Camera Raw Previews: Needs imagick and exiftool.', \OCP\Util::ERROR);
}
