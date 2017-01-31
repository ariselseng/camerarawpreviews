<?php

use OCP\AppFramework\App;
use \OCP\Files\FileInfo;


$app = new App('camerarawpreviews');
$container = $app->getContainer();
$container->getServer()->query('PreviewManager')->registerProvider('/image\/x-dcraw/', function() { return new \OCA\CameraRawPreviews\RawPreview; });
// $container->getServer()->query('PreviewManager')->listProviders();