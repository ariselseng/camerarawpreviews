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
            'url' => '/data/Nikon/D600/DSC_3297.NEF',
            'filename' => 'Nikon_D600.NEF',
            'sha1' => '607599813cc5ea65e81595e07955a51f281bf0b7'
        ],
        [
            'url' => '/data/Canon/EOS 50D/IMG_9518.CR2',
            'filename' => 'Canon_EOS_50D.CR2',
            'sha1' => 'eea0eaa8bf907d483b6234eab001fdc85848c80b'
        ],
        [
            'url' => '/data/Adobe DNG Converter/Canon EOS 5D Mark III/5G4A9395-compressed-lossless.DNG',
            'filename' => 'Canon_EOS_5D_Mark_III.compressed-lossless.DNG',
            'sha1' => 'a18d4dae67cfc0a9673c01b2d4f14fab4be68580'
        ],
        [
            'url' => '/data/Canon/Canon EOS RP/IMG_0306.CR3',
            'filename' => 'Canon_EOS_RP.CR3',
            'sha1' => 'c01e5214b95b6eaba68b92406b0804bd58800bcb'
        ],
        [
            'url' => '/data/Canon/EOS D2000C/RAW_CANON_D2000.TIF',
            'filename' => 'Canon_EOS_2000C.TIF',
            'sha1' => 'b68b5c7d4b944fff0ad9d28e68f405f957429c49'
        ],
        [
            'url' => '/data/Fujifilm/X-A1/DSCF2482.RAF',
            'filename' => 'Fujifilm_X-A1_DSCF2482.RAF',
            'sha1' => '82e625be5689bbd08a08dd9a9c5d38e21c80bf33'
        ],
        [
            'url' => '/data/Hasselblad/CF132/RAW_HASSELBLAD_IXPRESS_CF132.3FR',
            'filename' => 'Hasselblad_CF132.3FR',
            'sha1' => 'bcaa4c329711a8effb59682a99df3f2b15009d87'
        ]
    ];

    static function setupBeforeClass()
    {
        foreach (self::ASSETS as $test) {
            $localPath = sys_get_temp_dir() . '/' . $test['filename'];

            if (file_exists($localPath) && sha1_file($localPath) === $test['sha1']) {
                continue;
            }

            $content = file_get_contents('https://raw.pixls.us/' . str_replace(' ', '%20', $test['url']));
            if ($content !== false && sha1($content) === $test['sha1']) {
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
