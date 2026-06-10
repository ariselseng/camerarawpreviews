<?php

namespace OCA\CameraRawPreviews\Tests;

use OCA\CameraRawPreviews\Preview\Support\OrientationReader;
use PHPUnit\Framework\TestCase;

// OrientationReader is dependency-free (pure PHP TIFF-IFD parsing). Load it
// directly so this stays part of the fast, container-free unit suite.
if (!class_exists(OrientationReader::class)) {
    require_once __DIR__ . '/../lib/Preview/Support/OrientationReader.php';
}

/**
 * Unit tests for the pure-PHP TIFF Orientation (0x0112) reader.
 *
 * All fixtures are hand-built minimal TIFF headers — no Imagick, no sample
 * files — exercising both byte orders and the various malformed-input paths.
 */
class OrientationReaderTest extends TestCase
{
    private const ORIENTATION_TAG = 0x0112;
    private const TYPE_SHORT = 3;

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

    public function testReadsOrientationFromLittleEndianTiff(): void
    {
        $path = $this->writeTmp($this->buildTiff(true, [
            [self::ORIENTATION_TAG, self::TYPE_SHORT, 1, 6],
        ]));

        $this->assertSame(6, OrientationReader::read($path));
    }

    public function testReadsOrientationFromBigEndianTiff(): void
    {
        $path = $this->writeTmp($this->buildTiff(false, [
            [self::ORIENTATION_TAG, self::TYPE_SHORT, 1, 8],
        ]));

        $this->assertSame(8, OrientationReader::read($path));
    }

    public function testReadsNormalOrientationOne(): void
    {
        $path = $this->writeTmp($this->buildTiff(true, [
            [self::ORIENTATION_TAG, self::TYPE_SHORT, 1, 1],
        ]));

        $this->assertSame(1, OrientationReader::read($path));
    }

    public function testFindsOrientationAmongOtherTags(): void
    {
        // ImageWidth (0x0100) and ImageLength (0x0101) precede Orientation;
        // entries must be in ascending tag order per the TIFF spec.
        $path = $this->writeTmp($this->buildTiff(true, [
            [0x0100, self::TYPE_SHORT, 1, 4000],
            [0x0101, self::TYPE_SHORT, 1, 3000],
            [self::ORIENTATION_TAG, self::TYPE_SHORT, 1, 3],
        ]));

        $this->assertSame(3, OrientationReader::read($path));
    }

    // ---------------------------------------------------------------------
    // Null / failure paths
    // ---------------------------------------------------------------------

    public function testReturnsNullWhenOrientationTagAbsent(): void
    {
        $path = $this->writeTmp($this->buildTiff(true, [
            [0x0100, self::TYPE_SHORT, 1, 4000],
        ]));

        $this->assertNull(OrientationReader::read($path));
    }

    public function testReturnsNullForOutOfRangeOrientation(): void
    {
        $path = $this->writeTmp($this->buildTiff(true, [
            [self::ORIENTATION_TAG, self::TYPE_SHORT, 1, 9],
        ]));

        $this->assertNull(OrientationReader::read($path));
    }

    public function testReturnsNullForNonTiffData(): void
    {
        $path = $this->writeTmp("\xFF\xD8\xFF" . random_bytes(64));
        $this->assertNull(OrientationReader::read($path));
    }

    public function testReturnsNullForBadMagicNumber(): void
    {
        // Correct II byte order but magic != 42.
        $path = $this->writeTmp("II\x99\x00\x08\x00\x00\x00" . str_repeat("\x00", 32));
        $this->assertNull(OrientationReader::read($path));
    }

    public function testReturnsNullForTruncatedHeader(): void
    {
        $path = $this->writeTmp("II\x2A");
        $this->assertNull(OrientationReader::read($path));
    }

    public function testReturnsNullForTruncatedIfd(): void
    {
        // Header promises an IFD with 3 entries but the entry table is cut off.
        $tiff = "II" . pack('v', 42) . pack('V', 8) . pack('v', 3) . "\x12\x01";
        $path = $this->writeTmp($tiff);

        $this->assertNull(OrientationReader::read($path));
    }

    public function testReturnsNullForNonexistentFile(): void
    {
        $this->assertNull(OrientationReader::read('/no/such/file/at/all.tiff'));
    }

    public function testReturnsNullForEmptyIfd(): void
    {
        // Entry count of zero is treated as "no orientation".
        $tiff = "II" . pack('v', 42) . pack('V', 8) . pack('v', 0) . pack('V', 0);
        $path = $this->writeTmp($tiff);

        $this->assertNull(OrientationReader::read($path));
    }

    // ---------------------------------------------------------------------
    // Fixture helpers
    // ---------------------------------------------------------------------

    /**
     * Build a minimal single-IFD TIFF.
     *
     * @param bool  $little  true for little-endian (II), false for big-endian (MM)
     * @param array $entries List of [tag, type, count, shortValue] tuples. Each
     *                       is encoded as a 12-byte IFD entry with the value
     *                       stored inline as a SHORT in the first 2 bytes.
     */
    private function buildTiff(bool $little, array $entries): string
    {
        $u16 = fn (int $v): string => $little ? pack('v', $v) : pack('n', $v);
        $u32 = fn (int $v): string => $little ? pack('V', $v) : pack('N', $v);

        $header = ($little ? 'II' : 'MM') . $u16(42) . $u32(8);

        $ifd = $u16(count($entries));
        foreach ($entries as [$tag, $type, $count, $value]) {
            // 12-byte entry: tag(2) type(2) count(4) value/offset(4).
            // SHORT value sits in the first 2 bytes of the value field; the
            // remaining 2 bytes are padding (zero).
            $ifd .= $u16($tag) . $u16($type) . $u32($count) . $u16($value) . "\x00\x00";
        }
        $ifd .= $u32(0); // no next IFD

        return $header . $ifd;
    }

    /**
     * Write data to a temp file registered for cleanup.
     */
    private function writeTmp(string $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crp_orient_');
        file_put_contents($path, $data);
        $this->tmpFiles[] = $path;
        return $path;
    }
}
