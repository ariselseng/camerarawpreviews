<?php

namespace OCA\CameraRawPreviews\Tests;

use OCA\CameraRawPreviews\PreviewExtractor;
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
        // K9: the owner's public sample pair. IMG_2949.ly.dng has a 256x171
        // JPEG in IFD0 and 1024x683 / 3888x2592 JPEG XL previews in SubIFDs;
        // IMG_2949.c7.dng is the JPEG-only control.
        'DNG_JXL' => [
            'url' => 'https://nx76363.your-storageshare.de/s/MZiAbKDsda9bRK3/download?path=%2FIMG_2949.ly.dng',
            'filename' => 'IMG_2949.ly.dng',
            'sha1' => '0b70c72ddf5e5a68d16798a174f9807fd676ba89'
        ],
        'DNG_JPEG' => [
            'url' => 'https://nx76363.your-storageshare.de/s/MZiAbKDsda9bRK3/download?path=%2FIMG_2949.c7.dng',
            'filename' => 'IMG_2949.c7.dng',
            'sha1' => '18a23054026041d3d5838b2103b65dedcd3e1ae0'
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
     * K9: the JXL DNG must produce a large preview. A 3000x3000 request only
     * reaches a long edge >= 2000 px if the 3888x2592 JXL preview was used;
     * falling back to the 256x171 IFD0 JPEG would leave it at 256 px.
     */
    public function testK9JxlDngPreviewUsesLargeEmbeddedJxlPreview(): void
    {
        $this->requireBundledJxlBinary();

        $asset = self::ASSETS['DNG_JXL'];
        $localFile = $this->fetchAsset($asset);

        $file = $this->userFolder->newFile($asset['filename'], file_get_contents($localFile));
        $preview = $this->previewManager->getPreview($file, 3000, 3000);

        $this->assertInstanceOf(ISimpleFile::class, $preview);
        $this->assertLargeJpegPreview($preview->getContent(), 2000);
    }

    /**
     * K9: the JPEG-only control reaches the same order of magnitude through the
     * existing embedded-JPEG path, so the JXL assertion above is meaningful.
     */
    public function testK9JpegControlReachesComparableResolution(): void
    {
        $asset = self::ASSETS['DNG_JPEG'];
        $localFile = $this->fetchAsset($asset);

        $file = $this->userFolder->newFile($asset['filename'], file_get_contents($localFile));
        $preview = $this->previewManager->getPreview($file, 3000, 3000);

        $this->assertInstanceOf(ISimpleFile::class, $preview);
        $this->assertLargeJpegPreview($preview->getContent(), 2000);
    }

    /**
     * K9: a direct extractor assertion on the downloaded JXL sample. This is
     * the same façade the preview provider uses.
     */
    public function testK9JxlDngExtractsWithJxlMethodAndFullDimensions(): void
    {
        $this->requireBundledJxlBinary();

        $asset = self::ASSETS['DNG_JXL'];
        $localFile = $this->fetchAsset($asset);

        $method = null;
        $jpeg = PreviewExtractor::extractPreview($localFile, $method);

        $this->assertNotNull($jpeg, 'The JXL DNG should yield a preview');
        $this->assertSame('embedded JPEG XL', $method);

        $info = getimagesizefromstring($jpeg);
        $this->assertNotFalse($info, 'Extracted preview is not a parseable image');
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertSame(3888, $info[0], 'The 3888x2592 JXL preview should have been used');
        $this->assertSame(2592, $info[1]);
    }

    /**
     * K9: the JXL integration assertions only hold when the bundled binary for
     * this architecture has been rebuilt with `--jxl`. Mirror the unit suite's
     * real-binary guard so an un-rebuilt host skips instead of failing.
     */
    private function requireBundledJxlBinary(): void
    {
        $raw = strtolower(trim((string)php_uname('m')));
        $arch = match ($raw) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => 'aarch64',
            default => $raw,
        };

        $bin = dirname(__DIR__) . '/bin/rs-fallback-linux-' . $arch;
        if (!is_executable($bin)) {
            $this->markTestSkipped(
                'Bundled binary not found or not executable at ' . $bin
                . ' — rebuild it with rs-fallback/build.sh first.'
            );
        }
    }

    private function assertLargeJpegPreview(string $data, int $minLongEdge): void
    {
        $info = getimagesizefromstring($data);
        $this->assertNotFalse($info, 'Preview is not a parseable image');
        $this->assertSame(IMAGETYPE_JPEG, $info[2], 'Preview is not a JPEG');
        $longEdge = max($info[0], $info[1]);
        $this->assertGreaterThanOrEqual(
            $minLongEdge,
            $longEdge,
            sprintf('Preview long edge is %d px, expected at least %d px', $longEdge, $minLongEdge)
        );
    }

    /**
     * Returns a local copy of the asset, downloading it on first use. The copy
     * is reused as long as its checksum matches; it outlives the test
     * container when CRP_TEST_ASSET_DIR points at a volume (see the Makefile).
     * Without network access the test is skipped rather than failed, so the
     * rest of the suite still runs.
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
            $this->markTestSkipped('Could not download ' . $asset['url'] . ' (offline or checksum mismatch)');
        }
        file_put_contents($localPath, $content);

        return $localPath;
    }
}
