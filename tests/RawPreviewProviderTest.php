<?php

namespace OCA\CameraRawPreviews\Tests;

use OCA\CameraRawPreviews\RawPreviewIProviderV2;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\IMimeTypeDetector;
use OCP\IImage;
use OCP\IPreview;
use OCP\Lock\LockedException;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Integration tests for the preview provider and its registration. They need
 * the Nextcloud instance from tests/bootstrap.php, so they run with
 * `make tests`, not with the unit suite.
 *
 * The provider tests use mocked files, so they cover paths the end-to-end
 * RawPreviewIProviderV2Test cannot reach with files on local storage.
 */
class RawPreviewProviderTest extends TestCase
{
    /**
     * Only types Nextcloud does not map this way itself (CR2, NEF, ARW, DNG,
     * RAF and others it already maps to image/x-dcraw), so each case proves
     * the app's own registration took effect.
     *
     * @return array<string, array{string, string}> file name, expected MIME type
     */
    public static function rawFiles(): array
    {
        return [
            'Canon CR3' => ['IMG_0001.cr3', 'image/x-dcraw'],
            'Canon CRW' => ['CRW_0001.CRW', 'image/x-dcraw'],
            'Minolta MRW' => ['PICT0001.MRW', 'image/x-dcraw'],
            'Hasselblad 3FR' => ['B0000001.3FR', 'image/x-dcraw'],
            'Nikon NRW' => ['DSCN0001.NRW', 'image/x-dcraw'],
            'Samsung SRW' => ['SAM_0001.SRW', 'image/x-dcraw'],
            'Sigma X3F' => ['SDIM0001.X3F', 'image/x-dcraw'],
            // Overrides Nextcloud's own image/tiff
            'TIFF' => ['scan.tiff', 'image/x-dcraw'],
            'InDesign' => ['layout.indd', 'image/x-indesign'],
        ];
    }

    /**
     * Guards the app's use of the private Detection::registerTypeArray(): if a
     * Nextcloud release changes it, RAW files stop getting their MIME type and
     * so never reach the provider.
     */
    #[DataProvider('rawFiles')]
    public function testRawFileTypesAreRegistered(string $fileName, string $mimeType): void
    {
        $detector = Server::get(IMimeTypeDetector::class);

        $this->assertSame($mimeType, $detector->detectPath($fileName));
        $this->assertTrue(Server::get(IPreview::class)->isMimeSupported($mimeType));
    }

    public function testDefaultMappingsSurviveRegistration(): void
    {
        // Registering before Nextcloud loads its own mappings would replace
        // them all; Application calls getAllMappings() first to prevent that.
        $this->assertSame('image/jpeg', Server::get(IMimeTypeDetector::class)->detectPath('photo.jpg'));
    }

    public function testIsNotAvailableForEmptyFile(): void
    {
        $file = $this->createMock(FileInfo::class);
        $file->method('getSize')->willReturn(0);

        $this->assertFalse($this->provider()->isAvailable($file));
    }

    public function testIsAvailableForRawFile(): void
    {
        $file = $this->createMock(FileInfo::class);
        $file->method('getSize')->willReturn(1024);
        $file->method('getExtension')->willReturn('CR2');

        $this->assertTrue($this->provider()->isAvailable($file));
    }

    public function testThumbnailFromEncryptedFileIsCopiedAndScaled(): void
    {
        // Encrypted (or non-local) storage has no local path, so the provider
        // must copy the contents to a temp file first. This path crashed on
        // Nextcloud 34 when it still used a removed server getter.
        $file = $this->streamedFile(str_repeat("\x00", 4096) . $this->makeJpeg(400, 300) . str_repeat("\x00", 1024));

        $image = $this->provider()->getThumbnail($file, 100, 100);

        $this->assertInstanceOf(IImage::class, $image);
        $this->assertTrue($image->valid());
        $this->assertSame([100, 75], [$image->width(), $image->height()]);
    }

    public function testThumbnailIsNullAndLoggedWhenNoPreviewCanBeExtracted(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $file = $this->streamedFile(str_repeat("\x00", 4096));

        $this->assertNull($this->provider($logger)->getThumbnail($file, 100, 100));
    }

    public function testThumbnailIsNullAndLoggedWhenFileCannotBeRead(): void
    {
        // A locked file must not break preview generation for the request.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $file = $this->createMock(File::class);
        $file->method('isEncrypted')->willReturn(true);
        $file->method('fopen')->willThrowException(new LockedException('photo.CR2'));

        $this->assertNull($this->provider($logger)->getThumbnail($file, 100, 100));
    }

    // ---------------------------------------------------------------------
    // Fixture helpers
    // ---------------------------------------------------------------------

    private function provider(?LoggerInterface $logger = null): RawPreviewIProviderV2
    {
        return new RawPreviewIProviderV2($logger ?? $this->createMock(LoggerInterface::class));
    }

    /**
     * A file on encrypted storage whose contents can only be read as a stream.
     */
    private function streamedFile(string $contents): File
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $contents);
        rewind($stream);

        $file = $this->createMock(File::class);
        $file->method('isEncrypted')->willReturn(true);
        $file->method('fopen')->willReturn($stream);
        return $file;
    }

    private function makeJpeg(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefilledrectangle($img, 0, 0, $width, $height, imagecolorallocate($img, 30, 90, 160));

        ob_start();
        imagejpeg($img, null, 90);
        return ob_get_clean();
    }
}
