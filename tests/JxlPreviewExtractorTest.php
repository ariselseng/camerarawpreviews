<?php

namespace OCA\CameraRawPreviews\Tests;

use OCA\CameraRawPreviews\PreviewExtractor;
use OCA\CameraRawPreviews\Tests\Support\DngTiffBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// PreviewExtractor is dependency-free (pure PHP + GD), so this test can load
// it directly without booting Nextcloud.
if (!class_exists(PreviewExtractor::class)) {
    require_once __DIR__ . '/../lib/PreviewExtractor.php';
}
require_once __DIR__ . '/Support/DngTiffBuilder.php';

/**
 * Tests for embedded JPEG XL previews in DNG/TIFF-based RAWs.
 *
 * Every test goes through the public façade
 * {@see PreviewExtractor::extractPreview()}, so the tests do not depend on the
 * name of the new extractor class. Synthetic TIFFs are built with real TIFF
 * structures by {@see DngTiffBuilder}; the parser is never mocked.
 *
 * Requirement map (K1–K8; K9 lives in RawPreviewIProviderV2Test):
 *   K1  testK1_*
 *   K2  testK2_*
 *   K3  testK3_*
 *   K4  testK4_*
 *   K5  testK5_*
 *   K6  testK6_*
 *   K7  testK7_*
 *   K8  testK8_*
 *
 * The converter is the real bundled binary for the success path and a fake
 * script (installed through CRP_CLI_PATH) for every failure path. The fake
 * records each `--jxl` invocation so the tests can prove whether the converter
 * was called and inspect the temp file it received.
 */
class JxlPreviewExtractorTest extends TestCase
{
    private const METHOD = 'embedded JPEG XL';
    private const FIXTURE_JXL_CONTAINER = __DIR__ . '/fixtures/preview-1024.jxl';
    private const FIXTURE_JXL_TINY = __DIR__ . '/fixtures/tiny-64x48.jxl';
    private const FIXTURE_JXL_WIDE = __DIR__ . '/fixtures/wide-5000x100.jxl';

    /** @var string[] */
    private array $tmpFiles = [];

    /** @var string[] */
    private array $tmpDirs = [];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach ([
            'CRP_CLI_PATH',
            'CRP_JXL_TIMEOUT_SECONDS',
            'FAKE_JXL_MODE',
            'FAKE_JXL_JPEG',
            'FAKE_JXL_MARKER',
            'FAKE_JXL_SLEEP',
        ] as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name); // start every test without a converter override
        }
        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        $this->savedEnv = [];

        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tmpFiles = [];

        foreach ($this->tmpDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        $this->tmpDirs = [];

        parent::tearDown();
    }

    // =====================================================================
    // K1 — detect JXL preview IFDs, ignore raw/primary IFDs
    // =====================================================================

    public function testK1_detectsJxlPreviewIfdAndIgnoresSmallerJpeg(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683)
                ->addJpegPreview($this->makeJpeg(320, 240), 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($fakeJpeg, $result, 'The converter output should be returned');
        $this->assertSame(self::METHOD, $method);
        $this->assertMarkerSha1(sha1($jxl));
    }

    public function testK1_acceptsLegacyCompression50002(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683, ['compression' => DngTiffBuilder::COMPRESSION_JXL_LEGACY])
                ->addJpegPreview($this->makeJpeg(320, 240), 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($fakeJpeg, $result);
        $this->assertSame(self::METHOD, $method);
        $this->assertMarkerSha1(sha1($jxl));
    }

    /**
     * @return array<string, array{int, int, array<int, int>}>
     */
    public static function allowedPhotometrics(): array
    {
        return [
            'BlackIsZero' => [DngTiffBuilder::PHOTOMETRIC_BLACK_IS_ZERO, 1, [8]],
            'RGB' => [DngTiffBuilder::PHOTOMETRIC_RGB, 3, [8, 8, 8]],
            'YCbCr' => [DngTiffBuilder::PHOTOMETRIC_YCBCR, 3, [8, 8, 8]],
        ];
    }

    #[DataProvider('allowedPhotometrics')]
    public function testK1_acceptsAllowedPhotometricInterpretations(int $photometric, int $samples, array $bits): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683, [
                    'photometric' => $photometric,
                    'samplesPerPixel' => $samples,
                    'bitsPerSample' => $bits,
                ])
                ->addJpegPreview($this->makeJpeg(320, 240), 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($fakeJpeg, $result);
        $this->assertSame(self::METHOD, $method);
        $this->assertMarkerSha1(sha1($jxl));
    }

    public function testK1_ignoresPrimaryJxlIfd(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $jpeg = $this->makeJpeg(320, 240);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683, ['newSubfileType' => 0])
                ->addJpegPreview($jpeg, 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($jpeg, $result, 'A primary/raw IFD is never a preview');
        $this->assertSame('embedded JPEG', $method);
        $this->assertNull($this->marker(), 'The converter must not be invoked');
    }

    public function testK1_ignoresLinearRawJxlIfd(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $jpeg = $this->makeJpeg(320, 240);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683, [
                    'photometric' => DngTiffBuilder::PHOTOMETRIC_LINEAR_RAW,
                    'bitsPerSample' => [16, 16, 16],
                ])
                ->addJpegPreview($jpeg, 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($jpeg, $result, 'JXL-compressed LinearRaw is not a preview');
        $this->assertSame('embedded JPEG', $method);
        $this->assertNull($this->marker());
    }

    public function testK1_ignoresCfaJxlIfd(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $jpeg = $this->makeJpeg(320, 240);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683, [
                    'photometric' => DngTiffBuilder::PHOTOMETRIC_CFA,
                    'samplesPerPixel' => 1,
                    'bitsPerSample' => [16],
                ])
                ->addJpegPreview($jpeg, 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($jpeg, $result);
        $this->assertSame('embedded JPEG', $method);
        $this->assertNull($this->marker());
    }

    public function testK1_ignoresJxlIfdWithoutStripOrTileData(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $jpeg = $this->makeJpeg(320, 240);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683, ['omitStripTags' => true])
                ->addJpegPreview($jpeg, 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($jpeg, $result, 'An IFD without strip/tile data is not a candidate');
        $this->assertSame('embedded JPEG', $method);
        $this->assertNull($this->marker());
    }

    public function testK1_ignoresJxlIfdWithZeroDimensions(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $jpeg = $this->makeJpeg(320, 240);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 0, 0)
                ->addJpegPreview($jpeg, 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($jpeg, $result, 'Zero-sized images are not candidates');
        $this->assertSame('embedded JPEG', $method);
        $this->assertNull($this->marker());
    }

    public function testK1_usesJxlPreviewWhenThereIsNoJpeg(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($fakeJpeg, $result);
        $this->assertSame(self::METHOD, $method);
        $this->assertMarkerSha1(sha1($jxl));
    }

    // =====================================================================
    // K2 — JXL wins only when strictly larger than the best JPEG
    // =====================================================================

    public function testK2_equalAreaKeepsJpegAndDoesNotInvokeConverter(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $jpeg = $this->makeJpeg(1024, 683);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683)
                ->addJpegPreview($jpeg, 1024, 683)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($jpeg, $result, 'Equal area must keep the existing JPEG');
        $this->assertSame('embedded JPEG', $method);
        $this->assertNull($this->marker(), 'Equal area must not pay for conversion');
    }

    public function testK2_smallerJxlKeepsJpegAndDoesNotInvokeConverter(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_TINY);
        $jpeg = $this->makeJpeg(320, 240);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 64, 48)
                ->addJpegPreview($jpeg, 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($jpeg, $result, 'A smaller JXL preview must not replace the JPEG');
        $this->assertSame('embedded JPEG', $method);
        $this->assertNull($this->marker());
    }

    public function testK2_largerJxlWins(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683)
                ->addJpegPreview($this->makeJpeg(320, 240), 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($fakeJpeg, $result);
        $this->assertSame(self::METHOD, $method);
        $this->assertMarkerSha1(sha1($jxl));
    }

    // =====================================================================
    // K3 — real bundled binary: decodable JPEG, 4096 cap, no upscaling
    // =====================================================================

    public function testK3_realBinaryConvertsJxlContainerToDecodableJpeg(): void
    {
        $this->useRealBinary();

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683)
                ->addJpegPreview($this->makeJpeg(320, 240), 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertNotNull($result, 'The rebuilt binary should convert the JXL container');
        $this->assertSame(self::METHOD, $method);
        $this->assertJpeg(1024, 683, $result);
    }

    public function testK3_realBinaryDecodesBareCodestreamWithoutUpscaling(): void
    {
        $this->useRealBinary();

        $jxl = $this->fixture(self::FIXTURE_JXL_TINY);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 64, 48)
                ->addJpegPreview($this->makeJpeg(32, 24), 32, 24)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertNotNull($result, 'The rebuilt binary should decode a bare JXL codestream');
        $this->assertSame(self::METHOD, $method);
        $this->assertJpeg(64, 48, $result, 'Small images must never be upscaled');
    }

    public function testK3_realBinaryCapsLongEdgeAt4096(): void
    {
        $this->useRealBinary();

        $jxl = $this->fixture(self::FIXTURE_JXL_WIDE);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 5000, 100)
                ->addJpegPreview($this->makeJpeg(320, 240), 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertNotNull($result);
        $this->assertSame(self::METHOD, $method);

        $info = getimagesizefromstring($result);
        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertSame(4096, $info[0], 'The long edge must be capped at 4096 px');
        // 100 * 4096 / 5000 = 81.92, so 81 or 82 depending on rounding.
        $this->assertContains($info[1], [81, 82], 'Aspect ratio must be preserved');
    }

    // =====================================================================
    // K4 — every conversion failure falls back to the embedded JPEG
    // =====================================================================

    public function testK4_unusableConverterPathFallsBackToJpeg(): void
    {
        // A path that does not exist: the JXL path must simply fail.
        putenv('CRP_CLI_PATH=' . sys_get_temp_dir() . '/no-such-camerarawpreviews-binary');

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $jpeg = $this->makeJpeg(320, 240);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683)
                ->addJpegPreview($jpeg, 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($jpeg, $result);
        $this->assertSame('embedded JPEG', $method);
    }

    public function testK4_oldBinaryWithoutJxlModeFallsBackToJpeg(): void
    {
        $this->fakeConverter('old');

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $jpeg = $this->makeJpeg(320, 240);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683)
                ->addJpegPreview($jpeg, 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($jpeg, $result);
        $this->assertSame('embedded JPEG', $method);
        $this->assertMarkerSha1(sha1($jxl), 'The old binary must have been tried');
    }

    public function testK4_nonZeroExitFallsBackToJpeg(): void
    {
        $this->fakeConverter('fail');

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $jpeg = $this->makeJpeg(320, 240);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683)
                ->addJpegPreview($jpeg, 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($jpeg, $result);
        $this->assertSame('embedded JPEG', $method);
        $this->assertMarkerSha1(sha1($jxl));
    }

    public function testK4_emptyOutputFallsBackToJpeg(): void
    {
        $this->fakeConverter('empty');

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $jpeg = $this->makeJpeg(320, 240);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683)
                ->addJpegPreview($jpeg, 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($jpeg, $result);
        $this->assertSame('embedded JPEG', $method);
        $this->assertMarkerSha1(sha1($jxl));
    }

    public function testK4_nonJpegOutputFallsBackToJpeg(): void
    {
        $this->fakeConverter('notjpeg');

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $jpeg = $this->makeJpeg(320, 240);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683)
                ->addJpegPreview($jpeg, 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($jpeg, $result, 'Output that is not a JPEG must be treated as failure');
        $this->assertSame('embedded JPEG', $method);
        $this->assertMarkerSha1(sha1($jxl));
    }

    public function testK4_crashedChildFallsBackToJpeg(): void
    {
        $this->fakeConverter('crash');

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $jpeg = $this->makeJpeg(320, 240);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683)
                ->addJpegPreview($jpeg, 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($jpeg, $result);
        $this->assertSame('embedded JPEG', $method);
        $this->assertMarkerSha1(sha1($jxl));
    }

    public function testK4_corruptJxlFallsBackToJpeg(): void
    {
        $this->fakeConverter('validate');

        // A truncated container keeps the JXL magic but cannot be decoded.
        $truncated = substr($this->fixture(self::FIXTURE_JXL_CONTAINER), 0, 100);
        $jpeg = $this->makeJpeg(320, 240);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($truncated, 1024, 683)
                ->addJpegPreview($jpeg, 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($jpeg, $result);
        $this->assertSame('embedded JPEG', $method);
        $this->assertNotNull($this->marker(), 'The corrupt candidate must have been attempted');
    }

    public function testK4_noJpegAndFailedConversionReachesFallbacksAndReturnsNull(): void
    {
        $this->fakeConverter('fail');

        $truncated = substr($this->fixture(self::FIXTURE_JXL_CONTAINER), 0, 100);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                // IFD0 without strip data makes Imagick fail too, so the whole
                // chain must end in null rather than an exception.
                ->ifd0Image([
                    'width' => 1,
                    'height' => 1,
                    'compression' => 1,
                    'photometric' => DngTiffBuilder::PHOTOMETRIC_BLACK_IS_ZERO,
                    'newSubfileType' => 0,
                    'samplesPerPixel' => 1,
                    'bitsPerSample' => [8],
                    'omitStripTags' => true,
                ])
                ->orientation(1)
                ->addJxlPreview($truncated, 1024, 683)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertNull($result, 'No JPEG and a failed conversion must end in null');
        $this->assertNull($method);
        $this->assertNotNull($this->marker(), 'The JXL path must have been attempted');
    }

    public function testK4_noJpegAndFailedConversionStillRunsImagickFallback(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is not available');
        }

        $this->fakeConverter('fail');

        $truncated = substr($this->fixture(self::FIXTURE_JXL_CONTAINER), 0, 100);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($truncated, 1024, 683)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertNotNull($result, 'The existing Imagick fallback must still run');
        $this->assertSame('Imagick TIFF render', $method);
    }

    // =====================================================================
    // K5 — orientation from the preview IFD, else IFD0; never twice
    // =====================================================================

    public function testK5_usesPreviewIfdOrientation(): void
    {
        $fakeJpeg = $this->fakeJpeg(320, 240);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683, ['orientation' => 6])
                ->addJpegPreview($this->makeJpeg(320, 240), 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertNotNull($result);
        $this->assertSame(self::METHOD, $method);
        $this->assertJpeg(240, 320, $result, 'The preview IFD orientation must be applied');
    }

    public function testK5_fallsBackToIfd0OrientationWhenPreviewHasNone(): void
    {
        $fakeJpeg = $this->fakeJpeg(320, 240);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(6)
                ->addJxlPreview($jxl, 1024, 683)
                ->addJpegPreview($this->makeJpeg(320, 240), 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertNotNull($result);
        $this->assertSame(self::METHOD, $method);
        $this->assertJpeg(240, 320, $result, 'IFD0 orientation must be used as the fallback');
    }

    public function testK5_previewIfdOrientationWinsOverIfd0(): void
    {
        $fakeJpeg = $this->fakeJpeg(320, 240);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                // IFD0 says rotate 90 CW; the preview IFD says 180. The
                // preview IFD is authoritative, so the dimensions stay 320x240.
                ->orientation(6)
                ->addJxlPreview($jxl, 1024, 683, ['orientation' => 3])
                ->addJpegPreview($this->makeJpeg(320, 240), 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertNotNull($result);
        $this->assertSame(self::METHOD, $method);
        $this->assertJpeg(320, 240, $result, 'The preview IFD orientation must win over IFD0');
    }

    public function testK5_doesNotRotateTwice(): void
    {
        $fakeJpeg = $this->fakeJpeg(320, 240);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(6)
                ->addJxlPreview($jxl, 1024, 683, ['orientation' => 6])
                ->addJpegPreview($this->makeJpeg(320, 240), 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertNotNull($result);
        $this->assertSame(self::METHOD, $method);
        // Double 90° rotation would come back as 320x240.
        $this->assertJpeg(240, 320, $result, 'The image must be rotated exactly once');
    }

    // =====================================================================
    // K6 — largest candidate wins; ties by IFD order; strips/tiles concatenate
    // =====================================================================

    public function testK6_largestJxlPreviewWins(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('success', $fakeJpeg);

        $tiny = $this->fixture(self::FIXTURE_JXL_TINY);
        $large = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                // Deliberately put the small one first: selection is by area,
                // not by IFD order.
                ->addJxlPreview($tiny, 64, 48)
                ->addJxlPreview($large, 1024, 683)
                ->addJpegPreview($this->makeJpeg(320, 240), 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($fakeJpeg, $result);
        $this->assertSame(self::METHOD, $method);
        $this->assertMarkerSha1(sha1($large), 'The largest JXL preview must be converted');
    }

    public function testK6_tiesGoToFirstIfdInOrder(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('validate', $fakeJpeg);

        $first = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $second = $this->fixture(self::FIXTURE_JXL_TINY);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                // Both IFDs declare 1024x683 (a tie); the first one must win.
                ->addJxlPreview($first, 1024, 683)
                ->addJxlPreview($second, 1024, 683)
                ->addJpegPreview($this->makeJpeg(320, 240), 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($fakeJpeg, $result);
        $this->assertSame(self::METHOD, $method);
        $this->assertMarkerSha1(sha1($first), 'A tie must go to the first IFD in order');
    }

    public function testK6_multiStripPreviewIsConcatenatedInOrder(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('validate', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $strips = str_split($jxl, 10000);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683, ['strips' => $strips])
                ->addJpegPreview($this->makeJpeg(320, 240), 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($fakeJpeg, $result);
        $this->assertSame(self::METHOD, $method);
        $this->assertMarkerSha1(sha1($jxl), 'Strips must be concatenated in strip order');
        $this->assertSame(strlen($jxl), $this->marker()['size'] ?? null);
    }

    public function testK6_tiledPreviewIsConcatenatedInOrder(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('validate', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $tiles = [
            ['bytes' => substr($jxl, 0, 20000)],
            ['bytes' => substr($jxl, 20000)],
        ];
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683, [
                    'strips' => [],
                    'tiles' => $tiles,
                    'tileWidth' => 512,
                    'tileHeight' => 683,
                ])
                ->addJpegPreview($this->makeJpeg(320, 240), 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($fakeJpeg, $result);
        $this->assertSame(self::METHOD, $method);
        $this->assertMarkerSha1(sha1($jxl), 'Tiles must be concatenated in tile order');
    }

    // =====================================================================
    // K7 — diagnostics label
    // =====================================================================

    public function testK7_methodLabelIsEmbeddedJpegXl(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683)
                ->addJpegPreview($this->makeJpeg(320, 240), 320, 240)
                ->build()
        );

        PreviewExtractor::extractPreview($path, $method);

        $this->assertSame('embedded JPEG XL', $method);
    }

    // =====================================================================
    // K8 — bounds checks, size/pixel limits, temp file hygiene
    // =====================================================================

    public function testK8_rejectsStripOffsetBeyondEndOfFile(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $jpeg = $this->makeJpeg(320, 240);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683, ['stripOffsets' => [0x7FFFFFF0]])
                ->addJpegPreview($jpeg, 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($jpeg, $result, 'An out-of-bounds strip offset must be rejected');
        $this->assertSame('embedded JPEG', $method);
        $this->assertNull($this->marker());
    }

    public function testK8_rejectsStripByteCountBeyondEndOfFile(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $jpeg = $this->makeJpeg(320, 240);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683, ['stripByteCounts' => [0x7FFFFFF0]])
                ->addJpegPreview($jpeg, 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($jpeg, $result, 'An out-of-bounds byte count must be rejected');
        $this->assertSame('embedded JPEG', $method);
        $this->assertNull($this->marker());
    }

    public function testK8_rejectsZeroByteCount(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $jpeg = $this->makeJpeg(320, 240);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683, ['stripByteCounts' => [0]])
                ->addJpegPreview($jpeg, 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($jpeg, $result, 'A zero-byte preview must be rejected');
        $this->assertSame('embedded JPEG', $method);
        $this->assertNull($this->marker());
    }

    public function testK8_rejectsByteCountAbove64MiB(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $jpeg = $this->makeJpeg(320, 240);
        $overLimit = 64 * 1024 * 1024 + 1;

        $bytes = DngTiffBuilder::create()
            ->orientation(1)
            ->addJxlPreview($jxl, 1024, 683, ['stripByteCounts' => [$overLimit]])
            ->addJpegPreview($jpeg, 320, 240)
            ->build();

        // The strip must be *in bounds* so the 64 MiB guard is the only thing
        // that can reject it. Writing the byte count alone on a ~60 KiB file
        // would be rejected by the bounds check instead, which proves nothing.
        // The JXL strip sits near the start of the TIFF, so growing the file
        // sparsely past the limit keeps offset + byteCount within the file.
        $path = $this->writeSparseTmp($bytes, $overLimit + 1024 * 1024);
        $this->assertGreaterThan(
            $overLimit,
            filesize($path),
            'The sparse fixture must be larger than the strip so only the size guard can reject it'
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($jpeg, $result, 'A preview above 64 MiB must be rejected before decoding');
        $this->assertSame('embedded JPEG', $method);
        $this->assertNull($this->marker(), 'The converter must not be invoked for an over-limit preview');
    }

    public function testK8_rejectsMoreThan100Megapixels(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $jpeg = $this->makeJpeg(320, 240);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 20000, 6000) // 120 MP
                ->addJpegPreview($jpeg, 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($jpeg, $result, 'More than 100 MP must be rejected before decoding');
        $this->assertSame('embedded JPEG', $method);
        $this->assertNull($this->marker());
    }

    public function testK8_acceptsExactly100Megapixels(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 10000, 10000) // exactly 100 MP
                ->addJpegPreview($this->makeJpeg(320, 240), 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($fakeJpeg, $result, 'Exactly 100 MP is still allowed');
        $this->assertSame(self::METHOD, $method);
        $this->assertMarkerSha1(sha1($jxl));
    }

    public function testK8_malformedSubIfdOffsetDoesNotThrow(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $jpeg = $this->makeJpeg(320, 240);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->subIfdsOffset(0x7FFFFFF0)
                ->addJxlPreview($jxl, 1024, 683)
                ->addJpegPreview($jpeg, 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($jpeg, $result, 'A malformed SubIFD table must not throw');
        $this->assertSame('embedded JPEG', $method);
        $this->assertNull($this->marker());
    }

    public function testK8_tempFileIsOwnerOnlyAndRemovedAfterSuccess(): void
    {
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683)
                ->addJpegPreview($this->makeJpeg(320, 240), 320, 240)
                ->build()
        );

        PreviewExtractor::extractPreview($path, $method);

        $marker = $this->marker();
        $this->assertNotNull($marker);
        $this->assertIsString($marker['path'] ?? null);
        $this->assertNotNull($marker['mode'] ?? null, 'The converter must have recorded the temp file mode');
        $this->assertFileDoesNotExist($marker['path'], 'The JXL temp file must be deleted');
        $this->assertSame(0, $marker['mode'] & 0077, 'The JXL temp file must be owner-only');
    }

    public function testK8_tempFileIsRemovedAfterFailure(): void
    {
        $this->fakeConverter('fail');

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683)
                ->addJpegPreview($this->makeJpeg(320, 240), 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertNotNull($result);
        $marker = $this->marker();
        $this->assertNotNull($marker);
        $this->assertFileDoesNotExist($marker['path'], 'The temp file must be deleted on failure too');
    }

    public function testK8_converterPathWithSpaceIsPassedWithoutShell(): void
    {
        // A path with a space breaks a naive shell command; an argv-array
        // invocation handles it. This also keeps the "no shell" contract honest.
        $dir = sys_get_temp_dir() . '/crp fake ' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $this->tmpDirs[] = $dir;
        $script = $dir . '/converter script.php';
        copy(__DIR__ . '/Support/fake-jxl-converter.php', $script);
        chmod($script, 0755);

        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->installFakeConverter($script, 'success', $fakeJpeg);

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683)
                ->addJpegPreview($this->makeJpeg(320, 240), 320, 240)
                ->build()
        );

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertSame($fakeJpeg, $result);
        $this->assertSame(self::METHOD, $method);
    }

    public function testK8_converterTimeoutFallsBackToJpeg(): void
    {
        // The production timeout is 30 s, far too slow for a unit test, so the
        // spec lets CRP_JXL_TIMEOUT_SECONDS shorten it. The fake sleeps much
        // longer than the override; the extractor must kill it, fall back and
        // not block for the fake's full sleep.
        $fakeJpeg = $this->fakeJpeg(200, 150);
        $this->fakeConverter('sleep', $fakeJpeg);
        putenv('FAKE_JXL_SLEEP=10');
        putenv('CRP_JXL_TIMEOUT_SECONDS=1');

        $jxl = $this->fixture(self::FIXTURE_JXL_CONTAINER);
        $jpeg = $this->makeJpeg(320, 240);
        $path = $this->writeTmp(
            DngTiffBuilder::create()
                ->orientation(1)
                ->addJxlPreview($jxl, 1024, 683)
                ->addJpegPreview($jpeg, 320, 240)
                ->build()
        );

        $start = microtime(true);
        $result = PreviewExtractor::extractPreview($path, $method);
        $elapsed = microtime(true) - $start;

        $this->assertSame($jpeg, $result, 'A timed-out conversion must fall back to the embedded JPEG');
        $this->assertSame('embedded JPEG', $method);
        $this->assertLessThan(
            6.0,
            $elapsed,
            'The converter must be killed at the timeout instead of blocking for the full sleep'
        );

        $marker = $this->marker();
        $this->assertNotNull($marker, 'The converter must have been started before it timed out');
        $this->assertFileDoesNotExist($marker['path'], 'The temp file must be deleted even after a timeout');
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function fixture(string $path): string
    {
        $data = file_get_contents($path);
        $this->assertIsString($data, 'Missing fixture ' . $path);
        return $data;
    }

    private function makeJpeg(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefilledrectangle($img, 0, 0, $width, $height, imagecolorallocate($img, 30, 90, 160));
        imagefilledellipse(
            $img,
            (int)($width / 2),
            (int)($height / 2),
            max(1, (int)($width / 2)),
            max(1, (int)($height / 2)),
            imagecolorallocate($img, 220, 180, 40)
        );

        ob_start();
        imagejpeg($img, null, 90);
        $data = ob_get_clean();

        return $data;
    }

    private function fakeJpeg(int $width, int $height): string
    {
        return $this->makeJpeg($width, $height);
    }

    private function writeTmp(string $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crp_test_');
        file_put_contents($path, $data);
        $this->tmpFiles[] = $path;
        return $path;
    }

    /**
     * Writes $data and then grows the file to $totalSize with a sparse tail, so
     * a large strip byte count stays within the file bounds without actually
     * writing tens of megabytes of zeros.
     */
    private function writeSparseTmp(string $data, int $totalSize): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crp_test_');
        $this->tmpFiles[] = $path;

        $handle = fopen($path, 'wb');
        $this->assertIsResource($handle, 'Could not open the sparse fixture for writing');
        fwrite($handle, $data);
        ftruncate($handle, $totalSize);
        fclose($handle);
        clearstatcache(true, $path);

        return $path;
    }

    private function tempPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crp_marker_');
        @unlink($path);
        $this->tmpFiles[] = $path;
        return $path;
    }

    private function copyFakeScript(): string
    {
        $src = __DIR__ . '/Support/fake-jxl-converter.php';
        $dst = tempnam(sys_get_temp_dir(), 'crp_fake_');
        copy($src, $dst);
        chmod($dst, 0755);
        $this->tmpFiles[] = $dst;
        return $dst;
    }

    private function fakeConverter(string $behavior = 'success', ?string $jpeg = null): string
    {
        return $this->installFakeConverter($this->copyFakeScript(), $behavior, $jpeg);
    }

    private function installFakeConverter(string $script, string $behavior = 'success', ?string $jpeg = null): string
    {
        $jpegPath = $this->writeTmp($jpeg ?? $this->fakeJpeg(200, 150));
        $marker = $this->tempPath();

        putenv('CRP_CLI_PATH=' . $script);
        putenv('FAKE_JXL_MODE=' . $behavior);
        putenv('FAKE_JXL_JPEG=' . $jpegPath);
        putenv('FAKE_JXL_MARKER=' . $marker);

        return $script;
    }

    private function useRealBinary(): void
    {
        $bin = __DIR__ . '/../bin/rs-fallback-linux-' . $this->hostArch();
        if (!is_executable($bin)) {
            $this->markTestSkipped("Bundled binary not found at $bin — run rs-fallback/build.sh first.");
        }

        putenv('CRP_CLI_PATH=' . $bin);
        putenv('FAKE_JXL_MODE');
        putenv('FAKE_JXL_JPEG');
        putenv('FAKE_JXL_MARKER');
    }

    private function hostArch(): string
    {
        $raw = strtolower(trim((string)php_uname('m')));
        return match ($raw) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => 'aarch64',
            default => $raw,
        };
    }

    private function marker(): ?array
    {
        $path = getenv('FAKE_JXL_MARKER');
        if (!is_string($path) || $path === '' || !is_file($path)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private function assertMarkerSha1(string $expectedSha1, string $message = ''): void
    {
        $marker = $this->marker();
        $this->assertNotNull($marker, $message !== '' ? $message : 'The converter must have been invoked');
        $this->assertSame($expectedSha1, $marker['sha1'] ?? null, $message);
    }

    private function assertJpeg(int $expectedWidth, int $expectedHeight, string $data, string $message = ''): void
    {
        $info = getimagesizefromstring($data);
        $this->assertNotFalse($info, $message !== '' ? $message : 'Result is not a parseable image');
        $this->assertSame(IMAGETYPE_JPEG, $info[2], 'Result is not a JPEG');
        $this->assertSame($expectedWidth, $info[0], $message !== '' ? $message : 'Unexpected width');
        $this->assertSame($expectedHeight, $info[1], $message !== '' ? $message : 'Unexpected height');
        $this->assertNotFalse(@imagecreatefromstring($data), 'JPEG does not fully decode');
    }
}
