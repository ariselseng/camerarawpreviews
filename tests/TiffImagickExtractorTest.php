<?php

namespace OCA\CameraRawPreviews\Tests;

use OCA\CameraRawPreviews\Preview\Support\FileReader;
use OCA\CameraRawPreviews\Preview\TiffImagickExtractor;
use PHPUnit\Framework\TestCase;

if (!class_exists(TiffImagickExtractor::class)) {
    require_once __DIR__ . '/../lib/PreviewExtractor.php';
}

/**
 * Tests for the Imagick TIFF fallback.
 */
class TiffImagickExtractorTest extends TestCase
{
    /** @var string[] */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        if (!extension_loaded('imagick') || count(\Imagick::queryFormats('TIFF')) === 0) {
            $this->markTestSkipped('Imagick with TIFF support is not installed');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tmpFiles = [];
        parent::tearDown();
    }

    public function testRendersTiffAndRestoresResourceLimits(): void
    {
        $path = $this->writeTiff(640, 480);

        $before = $this->memoryResourceLimit();

        $reader = FileReader::open($path);
        $this->assertNotNull($reader, 'Fixture could not be opened');
        try {
            $result = (new TiffImagickExtractor())->extract($reader);
        } finally {
            $reader->close();
        }

        $this->assertNotNull($result);
        $info = getimagesizefromstring($result);
        $this->assertNotFalse($info, 'Result is not a parseable image');
        $this->assertSame(IMAGETYPE_JPEG, $info[2], 'Result is not a JPEG');
        $this->assertSame(640, $info[0]);
        $this->assertSame(480, $info[1]);

        if ($before !== null) {
            $this->assertSame(
                $before,
                $this->memoryResourceLimit(),
                'Process-wide Imagick limits must be restored after the render'
            );
        }
    }

    public function testReturnsNullForNonImage(): void
    {
        $path = $this->writeTmp('II' . str_repeat("\x00", 512));
        $reader = FileReader::open($path);
        $this->assertNotNull($reader);

        try {
            $result = (new TiffImagickExtractor())->extract($reader);
        } finally {
            $reader->close();
        }

        $this->assertNull($result);
    }

    private function writeTiff(int $width, int $height): string
    {
        $path = $this->tempPath();
        $imagick = new \Imagick();
        $imagick->newImage($width, $height, new \ImagickPixel('rgb(30,90,160)'));
        $imagick->setImageFormat('tiff');
        $imagick->writeImage($path);
        $imagick->clear();
        return $path;
    }

    private function memoryResourceLimit(): ?int
    {
        if (!method_exists(\Imagick::class, 'getResourceLimit') || !defined('Imagick::RESOURCETYPE_MEMORY')) {
            return null;
        }
        try {
            return \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_MEMORY);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function tempPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crp_tiff_');
        $this->tmpFiles[] = $path;
        return $path;
    }

    private function writeTmp(string $data): string
    {
        $path = $this->tempPath();
        file_put_contents($path, $data);
        return $path;
    }
}
