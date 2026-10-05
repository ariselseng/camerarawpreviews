<?php

namespace OCA\CameraRawPreviews\Tests;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IPreview;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RawPreviewIProviderV2Test extends TestCase
{
    const ASSETS = [
        'NEF' => [
            'url' => 'https://raw.pixls.us/data/Nikon/D600/DSC_3297.NEF',
            'filename' => 'Фото".NEF',
            'sha1' => '607599813cc5ea65e81595e07955a51f281bf0b7'
        ],
        'CR2' => [
            'url' => 'https://raw.pixls.us/data/Canon/EOS 50D/IMG_9518.CR2',
            'filename' => 'Canon_EOS_50D.CR2',
            'sha1' => 'eea0eaa8bf907d483b6234eab001fdc85848c80b'
        ],
        'DNG' => [
            'url' => 'https://raw.pixls.us/data/Adobe DNG Converter/Canon EOS 5D Mark III/5G4A9395-compressed-lossless.DNG',
            'filename' => 'Canon_EOS_5D_Mark_III.compressed-lossless.DNG',
            'sha1' => 'a18d4dae67cfc0a9673c01b2d4f14fab4be68580'
        ],
        'RAF' => [
            'url' => 'https://raw.pixls.us/data/Fujifilm/X-A1/DSCF2482.RAF',
            'filename' => 'Fujifilm_X-A1_DSCF2482.RAF',
            'sha1' => '82e625be5689bbd08a08dd9a9c5d38e21c80bf33'
        ],
        '3FR' => [
            'url' => 'https://raw.pixls.us/data/Hasselblad/CF132/RAW_HASSELBLAD_IXPRESS_CF132.3FR',
            'filename' => 'Hasselblad_CF132.3FR',
            'sha1' => 'bcaa4c329711a8effb59682a99df3f2b15009d87'
        ],
    ];

    private Folder $userFolder;
    private IPreview $previewManager;

    public static function assets(): array
    {
        return array_map(fn ($asset) => [$asset], self::ASSETS);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->userFolder = Server::get(IRootFolder::class)->getUserFolder('admin');
        $this->previewManager = Server::get(IPreview::class);
    }

    protected function tearDown(): void
    {
        foreach (self::ASSETS as $asset) {
            if ($this->userFolder->nodeExists($asset['filename'])) {
                $this->userFolder->get($asset['filename'])->delete();
            }
        }
        parent::tearDown();
    }

    #[DataProvider('assets')]
    public function testGetThumbnail(array $asset): void
    {
        $localFile = $this->fetchAsset($asset);

        $file = $this->userFolder->newFile($asset['filename'], file_get_contents($localFile));
        $preview = $this->previewManager->getPreview($file, 100, 100);

        $this->assertInstanceOf(ISimpleFile::class, $preview);
    }

    /**
     * Returns a local copy of the asset, downloading it on first use. The copy
     * is reused as long as its checksum matches; it outlives the test
     * container when CRP_TEST_ASSET_DIR points at a volume (see the Makefile).
     */
    private function fetchAsset(array $asset): string
    {
        $dir = getenv('CRP_TEST_ASSET_DIR') ?: sys_get_temp_dir();
        $localPath = $dir . '/' . $asset['filename'];
        if (file_exists($localPath) && sha1_file($localPath) === $asset['sha1']) {
            return $localPath;
        }

        $content = @file_get_contents(str_replace(' ', '%20', $asset['url']));
        if ($content === false || sha1($content) !== $asset['sha1']) {
            $this->fail('Could not download ' . $asset['url'] . ' (missing or checksum mismatch)');
        }
        file_put_contents($localPath, $content);

        return $localPath;
    }
}
