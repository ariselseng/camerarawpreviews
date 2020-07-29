<?php

namespace OCA\CameraRawPreviews\Tests;

use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use PHPUnit\Framework\TestCase;

class RawPreviewTestIProviderV2 extends TestCase
{

    protected $app;
    protected $previewManager;
    protected $userFolder;
    const ASSETS = [
        [
            'url' => 'https://raw.pixls.us/data/Nikon/D600/DSC_3297.NEF',
            'filename' => 'Nikon_D600.NEF',
            'md5' => '20765ed19cc8059ef5fd605b90cea4e1'
        ],
        [
            'url' => 'https://raw.pixls.us/data/Canon/EOS%2050D/IMG_9518.CR2',
            'filename' => 'Canon_EOS_50D.CR2',
            'md5' => '73a2f97980f2ff99ccc66c673e7f0b75'
        ],
    ];

    static function setupBeforeClass()
    {
        foreach (self::ASSETS as $test) {
            $localPath = sys_get_temp_dir() . '/' . $test['filename'];

            if (file_exists($localPath) && md5_file($localPath) === $test['md5']) {
                continue;
            }
            $content = file_get_contents($test['url']);
            if ($content !== false && md5($content) === $test['md5']) {
                file_put_contents(sys_get_temp_dir() . '/' . $test['filename'], $content);
            }
        }
    }

    protected function setUp()
    {
        parent::setUp();
        $this->app = new \OCA\CameraRawPreviews\AppInfo\Application;
        $this->app->register();
        $server = $this->app->getContainer()->getServer();
        $this->userFolder = $server->getUserFolder('admin');
        $this->previewManager = $server->getPreviewManager();
    }

    protected function tearDown()
    {
        foreach (self::ASSETS as $test) {
            $this->userFolder->get($test['filename'])->delete();
        }
    }

    public function testGetThumbnail()
    {

        foreach (self::ASSETS as $test) {
            $localFile = sys_get_temp_dir() . '/' . $test['filename'];
            $file = $this->userFolder->newFile($test['filename'], stream_get_contents(fopen($localFile, 'r')));
            $preview = null;

            try {
                $preview = $this->previewManager->getPreview($file, 100, 100);
            } catch (NotFoundException $e) {
            }

            $this->assertInstanceOf(ISimpleFile::class, $preview);
        }

    }

}
