<?php

namespace OCA\CameraRawPreviews\AppInfo;

use OCA\CameraRawPreviews\RawPreview;
use OCP\AppFramework\App;

class Application extends App
{
    private $appName;

    public function __construct()
    {
        $this->appName = 'camerarawpreviews';

        parent::__construct($this->appName);
    }

    public function register()
    {
        $this->registerScripts();
        $container = $this->getContainer();
        $mimeTypeDetector = \OC::$server->getMimeTypeDetector();
        $mimeTypeDetector->getAllMappings(); // is really needed

        $mimes_to_detect = [
            'indd' => ['image/x-indesign'],
            '3fr' => ['image/x-dcraw'],
            'arw' => ['image/x-dcraw'],
            'cr2' => ['image/x-dcraw'],
            'cr3' => ['image/x-dcraw'],
            'crw' => ['image/x-dcraw'],
            'dng' => ['image/x-dcraw'],
            'erf' => ['image/x-dcraw'],
            'fff' => ['image/x-dcraw'],
            'iiq' => ['image/x-dcraw'],
            'kdc' => ['image/x-dcraw'],
            'mrw' => ['image/x-dcraw'],
            'nef' => ['image/x-dcraw'],
            'nrw' => ['image/x-dcraw'],
            'orf' => ['image/x-dcraw'],
            'ori' => ['image/x-dcraw'],
            'pef' => ['image/x-dcraw'],
            'raf' => ['image/x-dcraw'],
            'rw2' => ['image/x-dcraw'],
            'rwl' => ['image/x-dcraw'],
            'sr2' => ['image/x-dcraw'],
            'srf' => ['image/x-dcraw'],
            'srw' => ['image/x-dcraw'],
            'tif' => ['image/x-dcraw'],
            'x3f' => ['image/x-dcraw'],
        ];

        $mimeTypeDetector->registerTypeArray($mimes_to_detect);

        $previewManager = $container->getServer()->query('PreviewManager');
        $appName = $this->appName;
        $previewManager->registerProvider('/^((image\/x-dcraw)|(image\/x-indesign))(;+.*)*$/', function () use($appName)  {
            return new RawPreview($this->getContainer()->getServer()->getLogger(), $appName);
        });

    }

    private function registerScripts()
    {
        $eventDispatcher = \OC::$server->getEventDispatcher();
        $eventDispatcher->addListener('OCA\Files::loadAdditionalScripts', function () {
            script($this->appName, 'register-viewer');  // adds js/script.js
        });
    }

}
