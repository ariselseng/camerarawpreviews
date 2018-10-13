<?php

use OCP\AppFramework\App;
$app              = new App('camerarawpreviews');
$container        = $app->getContainer();
$mimeTypeDetector = \OC::$server->getMimeTypeDetector();

$mimes           = $mimeTypeDetector->getAllMappings();
$mimes_to_detect = [
	'crw' => 'image/x-canon-crw',
	'indd' => 'image/x-indesign',
	'mrw' => 'image/x-minolta-mrw',
	'nrw' => 'image/x-raw-nikon',
	'rw2' => 'image/x-panasonic-rw2',
	'srw' => 'image/x-samsung-srw'
];
foreach ($mimes_to_detect as $key => $mime) {
    if (!isset($mimes[$key])) {
        $mimeTypeDetector->registerType($key, $mime);
    }
}

$previewManager = $container->getServer()->query('PreviewManager');

$previewManager->registerProvider('/^((image\/x-dcraw)|(image\/x-canon-crw)|(image\/x-minolta-mrw)|(image\/x-panasonic-rw2)|(image\/x-samsung-srw)|(image\/x-raw-nikon)|(image\/x-indesign))(;+.*)*$/', function () {
    return new \OCA\CameraRawPreviews\RawPreview;
});
