<?php

use OCP\AppFramework\App;
use \OCP\Files\FileInfo;


$app = new App('camerarawpreviews');
$container = $app->getContainer();
$mimeTypeDetector = \OC::$server->getMimeTypeDetector();
$mimeTypeLoader = \OC::$server->getMimeTypeLoader();
// Register custom mimetype we can hook in the frontend
$mimeTypeDetector->getAllMappings();
$mimeTypeDetector->registerType('indd', 'image/x-indesign', 'application/x-indesign');

$previewManager = $container->getServer()->query('PreviewManager');
$previewManager->registerProvider('/image\/x-dcraw/', function() { return new \OCA\CameraRawPreviews\RawPreview; });
$previewManager->registerProvider('/image\/x-indesign/', function() { return new \OCA\CameraRawPreviews\IndesignPreview; });
