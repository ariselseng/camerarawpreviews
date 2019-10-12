<?php

namespace OCA\CameraRawPreviews\AppInfo;

use OCA\CameraRawPreviews\RawPreview;
use OCP\AppFramework\App;

class Application extends App
{
    private $appName = 'camerarawpreviews';

    public function __construct()
    {
        parent::__construct($this->appName);
    }

    public function register()
    {
        $this->registerScripts();
        $this->registerProvider();
    }

    private function registerScripts()
    {
        $eventDispatcher = $this->getContainer()->getServer()->getEventDispatcher();
        $eventDispatcher->addListener('OCA\Files::loadAdditionalScripts', function () {
            script($this->appName, 'register-viewer');  // adds js/script.js
        });
    }

    private function registerProvider()
    {
        $appName = $this->appName;
        $server = $this->getContainer()->getServer();
        $logger = $server->getLogger();
        $previewManager = $server->query('PreviewManager');
        $mimeTypeDetector = $server->getMimeTypeDetector();
        $mimeTypeDetector->getAllMappings(); // is really needed

        $mimesToDetect = [
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
        $mimeTypeDetector->registerTypeArray($mimesToDetect);

        $previewManager->registerProvider('/^((image\/x-dcraw)|(image\/x-indesign))(;+.*)*$/', function () use ($logger, $appName) {
            return new RawPreview($logger, $appName);
        });
    }

}