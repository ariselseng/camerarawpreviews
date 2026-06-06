<?php

namespace OCA\CameraRawPreviews\Tests;

use OCA\CameraRawPreviews\PreviewExtractor;
use PHPUnit\Framework\TestCase;

// Loading PreviewExtractor pulls in MrwExtractor and the FileReader/JpegValidator
// support classes, so the suite stays container-free.
if (!class_exists(PreviewExtractor::class)) {
    require_once __DIR__ . '/../lib/PreviewExtractor.php';
}

/**
 * Unit tests for the Minolta MRW preview extractor.
 *
 * Fixtures are synthetic MRW containers built at runtime: a "\0MRM" header, a
 * "\0TTW" block holding a minimal TIFF (IFD0 -> EXIF IFD -> Minolta MakerNote
 * IFD with the 0x0088/0x0089 preview offset/length tags), and a real GD JPEG
 * whose SOI first byte is clobbered exactly as Minolta stores it. No exiftool,
 * Imagick, or sample files required.
 */
class MrwExtractorTest extends TestCase
{
    private const TYPE_LONG = 4;
    private const TYPE_UNDEFINED = 7;

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
    // Happy paths
    // ---------------------------------------------------------------------

    public function testExtractsPreviewFromBigEndianMrw(): void
    {
        // Real MRW files are big-endian ("MM"), with the SOI clobbered to 0x00.
        $jpeg = $this->makeJpeg(320, 240);
        $path = $this->writeTmp($this->buildMrw($jpeg, false, 0x00));

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertNotNull($result);
        $this->assertSame('Minolta MRW preview', $method);
        $this->assertSame($jpeg, $result, 'Repaired preview should equal the original JPEG byte-for-byte');
        $this->assertDimensions(320, 240, $result);
    }

    public function testExtractsPreviewFromLittleEndianMrw(): void
    {
        // The container header is always big-endian, but the TTW TIFF may be
        // little-endian ("II"); the extractor honours the TIFF byte order.
        $jpeg = $this->makeJpeg(200, 150);
        $path = $this->writeTmp($this->buildMrw($jpeg, true, 0x00));

        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertNotNull($result);
        $this->assertSame('Minolta MRW preview', $method);
        $this->assertSame($jpeg, $result);
        $this->assertDimensions(200, 150, $result);
    }

    public function testRepairsSoiClobberedWithNonZeroByte(): void
    {
        // DSLR models (Maxxum/Dynax) clobber the SOI byte with 0x02, not 0x00.
        // The repair forces it back to 0xFF regardless of the stored value.
        $jpeg = $this->makeJpeg(256, 192);
        $container = $this->buildMrw($jpeg, false, 0x02);

        // Sanity: there must be NO intact JPEG SOI anywhere, otherwise the test
        // would pass via the generic scanner instead of exercising the repair.
        $this->assertFalse(strpos($container, "\xFF\xD8\xFF"), 'Fixture must not contain an intact SOI');

        $path = $this->writeTmp($container);
        $result = PreviewExtractor::extractPreview($path, $method);

        $this->assertNotNull($result);
        $this->assertSame('Minolta MRW preview', $method);
        $this->assertSame($jpeg, $result);
    }

    // ---------------------------------------------------------------------
    // Null / failure paths
    // ---------------------------------------------------------------------

    public function testReturnsNullWhenPreviewTagsMissing(): void
    {
        // MakerNote present but without the 0x0088/0x0089 preview tags.
        $jpeg = $this->makeJpeg(100, 100);
        $path = $this->writeTmp($this->buildMrw($jpeg, false, 0x00, true));

        $this->assertNull(PreviewExtractor::extractPreview($path));
    }

    public function testReturnsNullForNonMrwFile(): void
    {
        // A plain TIFF (no "\0MRM" magic) must not be claimed by this extractor.
        $tiff = "II\x2A\x00\x08\x00\x00\x00" . str_repeat("\x10", 4096);
        $path = $this->writeTmp($tiff);

        $this->assertNull(PreviewExtractor::extractPreview($path));
    }

    public function testReturnsNullWhenTtwBlockAbsent(): void
    {
        // Valid "\0MRM" header but only a PRD block, no TTW.
        $body = self::MAGIC_PADDING();
        $path = $this->writeTmp($body);

        $this->assertNull(PreviewExtractor::extractPreview($path));
    }

    // ---------------------------------------------------------------------
    // Fixture helpers
    // ---------------------------------------------------------------------

    /** An "\0MRM" file containing a single non-TTW (PRD) block. */
    private static function MAGIC_PADDING(): string
    {
        $prd = "\x00PRD" . pack('N', 8) . str_repeat("\x00", 8);
        return "\x00MRM" . pack('N', strlen($prd)) . $prd;
    }

    /**
     * Build a synthetic MRW container around the given JPEG.
     *
     * @param string $jpeg        The preview JPEG to embed.
     * @param bool   $little      TTW TIFF byte order: true = II, false = MM.
     * @param int    $clobberByte Value Minolta stored over the SOI's 0xFF byte.
     * @param bool   $omitPreview If true, leave out the 0x0088/0x0089 tags.
     */
    private function buildMrw(string $jpeg, bool $little, int $clobberByte, bool $omitPreview = false): string
    {
        // --- TTW TIFF body. All offsets below are relative to the TIFF base. ---
        // Layout: header(8) | IFD0(18) | EXIF IFD(18) | MakerNote IFD | preview
        $ifd0Off = 8;
        $exifOff = $ifd0Off + 18;      // 1 entry IFD = 2 + 12 + 4 = 18 bytes
        $makerOff = $exifOff + 18;

        $makerEntries = $omitPreview ? 0 : 2;
        $makerLen = 2 + $makerEntries * 12 + 4;
        $previewOff = $makerOff + $makerLen;
        $previewLen = strlen($jpeg);

        $header = ($little ? 'II' : 'MM') . $this->u16(42, $little) . $this->u32($ifd0Off, $little);

        $ifd0 = $this->u16(1, $little)
            . $this->entry(0x8769, self::TYPE_LONG, 1, $exifOff, $little)
            . $this->u32(0, $little);

        $exif = $this->u16(1, $little)
            . $this->entry(0x927C, self::TYPE_UNDEFINED, $makerLen, $makerOff, $little)
            . $this->u32(0, $little);

        $maker = $this->u16($makerEntries, $little);
        if (!$omitPreview) {
            $maker .= $this->entry(0x0088, self::TYPE_LONG, 1, $previewOff, $little)
                . $this->entry(0x0089, self::TYPE_LONG, 1, $previewLen, $little);
        }
        $maker .= $this->u32(0, $little);

        // Clobber the SOI: replace the leading 0xFF with the stored byte, as
        // Minolta does. The "\xD8\xFF…" tail stays intact.
        $stored = chr($clobberByte) . substr($jpeg, 1);

        $tiff = $header . $ifd0 . $exif . $maker . $stored;

        // --- MRW container: "\0MRM" + BE length + "\0TTW" block. ---
        $ttwBlock = "\x00TTW" . pack('N', strlen($tiff)) . $tiff;
        return "\x00MRM" . pack('N', strlen($ttwBlock)) . $ttwBlock;
    }

    /** Encode a 12-byte IFD entry. Only the value field is read by the parser. */
    private function entry(int $tag, int $type, int $count, int $value, bool $little): string
    {
        return $this->u16($tag, $little)
            . $this->u16($type, $little)
            . $this->u32($count, $little)
            . $this->u32($value, $little);
    }

    private function u16(int $v, bool $little): string
    {
        return $little ? pack('v', $v) : pack('n', $v);
    }

    private function u32(int $v, bool $little): string
    {
        return $little ? pack('V', $v) : pack('N', $v);
    }

    private function makeJpeg(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefilledrectangle($img, 0, 0, $width, $height, imagecolorallocate($img, 40, 100, 150));
        imagefilledellipse($img, (int)($width / 2), (int)($height / 2), (int)($width / 2), (int)($height / 2), imagecolorallocate($img, 210, 170, 30));
        ob_start();
        imagejpeg($img, null, 90);
        return ob_get_clean();
    }

    private function assertDimensions(int $expectedWidth, int $expectedHeight, string $jpeg): void
    {
        $info = getimagesizefromstring($jpeg);
        $this->assertNotFalse($info, 'Result is not a parseable image');
        $this->assertSame(IMAGETYPE_JPEG, $info[2], 'Result is not a JPEG');
        $this->assertSame($expectedWidth, $info[0], 'Unexpected width');
        $this->assertSame($expectedHeight, $info[1], 'Unexpected height');
    }

    private function writeTmp(string $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crp_mrw_');
        file_put_contents($path, $data);
        $this->tmpFiles[] = $path;
        return $path;
    }
}
