<?php

namespace OCA\CameraRawPreviews\Tests;

use OCA\CameraRawPreviews\PreviewExtractor;
use PHPUnit\Framework\TestCase;

// PreviewExtractor is dependency-free (pure PHP + GD), so the test can load it
// directly without booting Nextcloud. This keeps the unit suite fast and
// runnable outside the app container.
if (!class_exists(PreviewExtractor::class)) {
    require_once __DIR__ . '/../lib/PreviewExtractor.php';
}

/**
 * Unit tests for the pure-PHP preview extractor.
 *
 * All fixtures are synthesised at runtime (real GD-encoded JPEGs, hand-built
 * JPEG/InDesign containers) so the suite needs no network access or binary
 * sample files.
 */
class PreviewExtractorTest extends TestCase
{
    /** 16-byte master-page GUID every InDesign document starts with. */
    private const INDESIGN_GUID = "\x06\x06\xED\xF5\xD8\x1D\x46\xE5\xBD\x31\xEF\xE7\xFE\x74\xB7\x1D";

    /** @var string[] Temp files to remove after each test. */
    private array $tmpFiles = [];

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

    // ---------------------------------------------------------------------
    // Null / failure paths
    // ---------------------------------------------------------------------

    public function testReturnsNullForNonexistentFile(): void
    {
        $this->assertNull(PreviewExtractor::extractPreview('/no/such/file/at/all.raw'));
    }

    public function testReturnsNullForFileWithoutEmbeddedJpeg(): void
    {
        $path = $this->writeTmp(str_repeat("\x00", 4096) . 'not an image' . str_repeat("\x01", 4096));
        $this->assertNull(PreviewExtractor::extractPreview($path));
    }

    public function testReturnsNullForTruncatedJpeg(): void
    {
        // Starts like a JPEG (FF D8 FF) but is garbage that never decodes.
        $path = $this->writeTmp("\xFF\xD8\xFF" . random_bytes(2000));
        $this->assertNull(PreviewExtractor::extractPreview($path));
    }

    public function testReturnsNullForPlainTiffWithNoJpeg(): void
    {
        // Little-endian TIFF header followed by non-JPEG bytes. The extractor
        // itself does not render TIFFs (that's the Imagick fallback in the
        // provider/CLI), so it must report "no embedded preview" here.
        $tiff = "II\x2A\x00\x08\x00\x00\x00" . str_repeat("\x10", 8192);
        $path = $this->writeTmp($tiff);
        $this->assertNull(PreviewExtractor::extractPreview($path));
    }

    // ---------------------------------------------------------------------
    // Embedded-JPEG scanning
    // ---------------------------------------------------------------------

    public function testExtractsEmbeddedJpegFromContainer(): void
    {
        $jpeg = $this->makeJpeg(320, 240);
        // Wrap with non-JPEG filler to mimic a RAW container.
        $path = $this->writeTmp(str_repeat("\x00", 5000) . $jpeg . str_repeat("\x00", 3000));

        $result = PreviewExtractor::extractPreview($path);

        $this->assertNotNull($result);
        $this->assertSame($jpeg, $result, 'Returned bytes should be the embedded JPEG verbatim (no re-encode)');
        $this->assertDimensions(320, 240, $result);
    }

    public function testSelectsHighestResolutionJpeg(): void
    {
        $small = $this->makeJpeg(160, 120);
        $large = $this->makeJpeg(800, 600);

        // Deliberately place the small one first to prove selection is by
        // resolution, not file order.
        $path = $this->writeTmp(
            str_repeat("\x00", 1000) . $small .
            str_repeat("\x00", 1000) . $large .
            str_repeat("\x00", 1000)
        );

        $result = PreviewExtractor::extractPreview($path);

        $this->assertNotNull($result);
        $this->assertSame($large, $result);
        $this->assertDimensions(800, 600, $result);
    }

    public function testSkipsEmbeddedExifThumbnailAndReturnsFullImage(): void
    {
        // Regression test: an EXIF APP1 segment carrying a thumbnail contains
        // its own FF D8 ... FF D9. A naive "first FF D9" end-finder would
        // truncate the outer JPEG at the thumbnail. The segment walker must
        // skip the APP1 by its length and return the full-resolution image.
        $main = $this->makeJpeg(640, 480);
        $thumb = $this->makeJpeg(80, 60);
        $withThumb = $this->jpegWithEmbeddedThumbnail($main, $thumb);

        $path = $this->writeTmp(str_repeat("\x00", 2048) . $withThumb . str_repeat("\x00", 2048));

        $result = PreviewExtractor::extractPreview($path);

        $this->assertNotNull($result);
        $this->assertSame($withThumb, $result, 'Should return the full outer JPEG, not the thumbnail');
        $this->assertDimensions(640, 480, $result);
    }

    public function testExtractsJpegStraddlingChunkBoundary(): void
    {
        // The scanner reads in 1MB chunks with a small overlap. Place a JPEG so
        // its start marker and body cross the 1MB boundary to exercise the
        // overlap/carry logic.
        $jpeg = $this->makeJpeg(400, 300);
        $chunk = 1024 * 1024;
        $leading = $chunk - 4; // FF D8 FF straddles the boundary
        $path = $this->writeTmp(str_repeat("\x00", $leading) . $jpeg . str_repeat("\x00", 1000));

        $result = PreviewExtractor::extractPreview($path);

        $this->assertNotNull($result);
        $this->assertSame($jpeg, $result);
        $this->assertDimensions(400, 300, $result);
    }

    // ---------------------------------------------------------------------
    // InDesign page-image extraction
    // ---------------------------------------------------------------------

    public function testDetectsAndExtractsInDesignPageImage(): void
    {
        $jpeg = $this->makeJpeg(240, 160);
        $path = $this->writeTmp($this->indesignContainer([$jpeg]));

        $result = PreviewExtractor::extractPreview($path);

        $this->assertNotNull($result);
        $this->assertSame($jpeg, $result);
        $this->assertDimensions(240, 160, $result);
    }

    public function testInDesignReturnsFirstPageWhenMultipleValid(): void
    {
        // <xmpGImg:image> elements appear in page order; page 1 should win.
        $page1 = $this->makeJpeg(120, 90);
        $page2 = $this->makeJpeg(300, 200);
        $path = $this->writeTmp($this->indesignContainer([$page1, $page2]));

        $result = PreviewExtractor::extractPreview($path);

        $this->assertNotNull($result);
        $this->assertSame($page1, $result);
        $this->assertDimensions(120, 90, $result);
    }

    public function testInDesignSkipsUndecodableImageBlocks(): void
    {
        // First block is junk base64; the extractor must move on to the next
        // page rather than giving up.
        $valid = $this->makeJpeg(200, 150);
        $junkBlock = '<xmpGImg:image>' . base64_encode('this is not a jpeg at all') . '</xmpGImg:image>';
        $validBlock = '<xmpGImg:image>' . chunk_split(base64_encode($valid)) . '</xmpGImg:image>';

        $body = self::INDESIGN_GUID . 'DOCUMENT' . str_repeat("\x00", 64)
            . $junkBlock . str_repeat("\x00", 16) . $validBlock . str_repeat("\x00", 16);

        $path = $this->writeTmp($body);

        $result = PreviewExtractor::extractPreview($path);

        $this->assertNotNull($result);
        $this->assertSame($valid, $result);
        $this->assertDimensions(200, 150, $result);
    }

    public function testInDesignDecodesBase64WrappedWithXmlEntities(): void
    {
        // Real InDesign files line-wrap the base64 preview inside the XMP using
        // XML character references (&#xA;) rather than literal newlines. The
        // "x"/"A" of "&#xA;" are valid base64 characters, so they must be
        // entity-decoded before stripping whitespace or the stream corrupts.
        $jpeg = $this->makeJpeg(256, 144);
        $b64 = base64_encode($jpeg);
        // Wrap at 76 chars joined by the &#xA; entity, like InDesign does.
        $wrapped = implode('&#xA;', str_split($b64, 76));

        $body = self::INDESIGN_GUID . 'DOCUMENT' . str_repeat("\x00", 64)
            . '<xmpGImg:image>' . $wrapped . '</xmpGImg:image>' . str_repeat("\x00", 16);

        $path = $this->writeTmp($body);

        $result = PreviewExtractor::extractPreview($path);

        $this->assertNotNull($result);
        $this->assertSame($jpeg, $result);
        $this->assertDimensions(256, 144, $result);
    }

    public function testInDesignSkipsBinaryContaminatedCopyForCleanCopy(): void
    {
        // InDesign's object database can fragment a stream across pages, so some
        // copies of the preview are padded with binary/null bytes mid-base64.
        // The extractor must reject the contaminated copy (strict base64) and
        // fall through to a later, clean copy.
        $jpeg = $this->makeJpeg(300, 200);
        $b64 = base64_encode($jpeg);
        $wrapped = implode('&#xA;', str_split($b64, 76));

        // Contaminated copy: inject a run of null bytes into the middle.
        $half = (int)(strlen($wrapped) / 2);
        $contaminated = substr($wrapped, 0, $half) . str_repeat("\x00", 200) . substr($wrapped, $half);

        $body = self::INDESIGN_GUID . 'DOCUMENT' . str_repeat("\x00", 64)
            . '<xmpGImg:image>' . $contaminated . '</xmpGImg:image>' . str_repeat("\x00", 32)
            . '<xmpGImg:image>' . $wrapped . '</xmpGImg:image>' . str_repeat("\x00", 16);

        $path = $this->writeTmp($body);

        $result = PreviewExtractor::extractPreview($path);

        $this->assertNotNull($result);
        $this->assertSame($jpeg, $result, 'Should skip the contaminated copy and return the clean one');
        $this->assertDimensions(300, 200, $result);
    }

    public function testInDesignWithNoUsableImageReturnsNull(): void
    {
        $body = self::INDESIGN_GUID . 'DOCUMENT' . str_repeat("\x00", 256);
        $path = $this->writeTmp($body);

        $this->assertNull(PreviewExtractor::extractPreview($path));
    }

    // ---------------------------------------------------------------------
    // Fixture helpers
    // ---------------------------------------------------------------------

    /**
     * Create a real GD-encoded JPEG with the given dimensions.
     */
    private function makeJpeg(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        // Some colour variation so it is a genuine (non-trivial) encode.
        imagefilledrectangle($img, 0, 0, $width, $height, imagecolorallocate($img, 30, 90, 160));
        imagefilledellipse($img, (int)($width / 2), (int)($height / 2), (int)($width / 2), (int)($height / 2), imagecolorallocate($img, 220, 180, 40));

        ob_start();
        imagejpeg($img, null, 90);
        $data = ob_get_clean();

        return $data;
    }

    /**
     * Build a valid JPEG that embeds a smaller JPEG inside an APP1 (EXIF-style)
     * segment, right after the SOI marker.
     */
    private function jpegWithEmbeddedThumbnail(string $main, string $thumb): string
    {
        $payload = "Exif\x00\x00" . $thumb;
        $segmentLength = strlen($payload) + 2; // +2 for the length field itself
        $this->assertLessThan(65536, $segmentLength, 'Thumbnail too large for a single APP1 segment');

        $app1 = "\xFF\xE1" . chr(($segmentLength >> 8) & 0xFF) . chr($segmentLength & 0xFF) . $payload;

        // SOI + our APP1 + the rest of the main image (everything after its SOI).
        return "\xFF\xD8" . $app1 . substr($main, 2);
    }

    /**
     * Build a minimal InDesign-like document: the master-page GUID followed by
     * one <xmpGImg:image> block per page (base64, newline-wrapped like real XMP).
     *
     * @param string[] $jpegs One JPEG blob per page, in page order.
     */
    private function indesignContainer(array $jpegs): string
    {
        $body = self::INDESIGN_GUID . 'DOCUMENT' . str_repeat("\x00", 128);
        foreach ($jpegs as $jpeg) {
            $body .= '<xmpGImg:image>' . chunk_split(base64_encode($jpeg)) . '</xmpGImg:image>';
            $body .= str_repeat("\x00", 32);
        }
        return $body;
    }

    /**
     * Assert a JPEG blob decodes to the expected pixel dimensions.
     */
    private function assertDimensions(int $expectedWidth, int $expectedHeight, string $jpeg): void
    {
        $info = getimagesizefromstring($jpeg);
        $this->assertNotFalse($info, 'Result is not a parseable image');
        $this->assertSame(IMAGETYPE_JPEG, $info[2], 'Result is not a JPEG');
        $this->assertSame($expectedWidth, $info[0], 'Unexpected width');
        $this->assertSame($expectedHeight, $info[1], 'Unexpected height');
    }

    /**
     * Write data to a temp file registered for cleanup.
     */
    private function writeTmp(string $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crp_test_');
        file_put_contents($path, $data);
        $this->tmpFiles[] = $path;
        return $path;
    }
}
