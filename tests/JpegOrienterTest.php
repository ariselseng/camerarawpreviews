<?php

namespace OCA\CameraRawPreviews\Tests;

use OCA\CameraRawPreviews\Preview\Support\JpegOrienter;
use OCA\CameraRawPreviews\Tests\Support\TiffBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// JpegOrienter is dependency-free (pure PHP + GD). Load it directly so this
// stays part of the unit suite.
if (!class_exists(JpegOrienter::class)) {
    require_once __DIR__ . '/../lib/Preview/Support/JpegOrienter.php';
}

/**
 * Unit tests for baking an EXIF orientation into JPEG bytes.
 *
 * The fixture is a landscape JPEG with a red marker in its top-left corner on
 * a blue background. Each orientation moves that corner to a distinct place
 * (and the four transposing ones also swap width and height), so checking the
 * marker's corner and the dimensions pins down exactly which transform ran.
 */
class JpegOrienterTest extends TestCase
{
    private const WIDTH = 60;
    private const HEIGHT = 30;

    /**
     * @return array<string, array{int, string, bool}> orientation, corner the
     *         stored top-left ends up in, whether width and height swap
     */
    public static function orientations(): array
    {
        return [
            '2 mirror horizontal' => [2, 'top-right', false],
            '3 rotate 180' => [3, 'bottom-right', false],
            '4 mirror vertical' => [4, 'bottom-left', false],
            '5 transpose' => [5, 'top-left', true],
            '6 rotate 90 CW' => [6, 'top-right', true],
            '7 transverse' => [7, 'bottom-right', true],
            '8 rotate 90 CCW' => [8, 'bottom-left', true],
        ];
    }

    #[DataProvider('orientations')]
    public function testAppliesContainerOrientation(int $orientation, string $corner, bool $swapped): void
    {
        $result = JpegOrienter::makeUpright($this->makeMarkedJpeg(), $orientation);

        $this->assertUpright($result, $corner, $swapped);
    }

    public function testLeavesUprightJpegUntouched(): void
    {
        $jpeg = $this->makeMarkedJpeg();

        $this->assertSame($jpeg, JpegOrienter::makeUpright($jpeg, 1));
        $this->assertSame($jpeg, JpegOrienter::makeUpright($jpeg, null));
    }

    public function testIgnoresOutOfRangeOrientation(): void
    {
        $jpeg = $this->makeMarkedJpeg();

        $this->assertSame($jpeg, JpegOrienter::makeUpright($jpeg, 9));
        $this->assertSame($jpeg, JpegOrienter::makeUpright($jpeg, 0));
    }

    public function testOwnExifOrientationWinsOverContainer(): void
    {
        // The preview says 6 (rotate CW), the container says 3 (rotate 180).
        $jpeg = $this->withExifOrientation($this->makeMarkedJpeg(), 6);

        $result = JpegOrienter::makeUpright($jpeg, 3);

        $this->assertUpright($result, 'top-right', true);
    }

    public function testOwnExifOrientationOneMeansUpright(): void
    {
        // An explicit "upright" in the preview must not be overridden by the
        // container, and must not cost a re-encode.
        $jpeg = $this->withExifOrientation($this->makeMarkedJpeg(), 1);

        $this->assertSame($jpeg, JpegOrienter::makeUpright($jpeg, 6));
    }

    public function testResultCarriesNoOrientationOfItsOwn(): void
    {
        // Otherwise Nextcloud would rotate the already-upright result again.
        $jpeg = $this->withExifOrientation($this->makeMarkedJpeg(), 6);

        $result = JpegOrienter::makeUpright($jpeg, null);

        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($result));
        $this->assertFalse(is_array($exif) && isset($exif['Orientation']), 'Result still has an Orientation tag');
    }

    public function testReturnsUndecodableInputUnchanged(): void
    {
        $garbage = "\xFF\xD8\xFF" . str_repeat("\x42", 64);

        $this->assertSame($garbage, JpegOrienter::makeUpright($garbage, 6));
    }

    public function testReturnsAbsurdDimensionsUnchangedWithoutDecoding(): void
    {
        $jpeg = $this->makeMarkedJpeg();
        $pos = strpos($jpeg, "\xFF\xC0");
        $this->assertNotFalse($pos, 'Fixture has no SOF0 marker');

        // Claim 65535x65535 pixels; the entropy data is still the tiny image.
        $jpeg[$pos + 5] = "\xFF";
        $jpeg[$pos + 6] = "\xFF";
        $jpeg[$pos + 7] = "\xFF";
        $jpeg[$pos + 8] = "\xFF";

        $this->assertSame($jpeg, JpegOrienter::makeUpright($jpeg, 6));
    }

    // ---------------------------------------------------------------------
    // Fixture helpers
    // ---------------------------------------------------------------------

    /**
     * A blue landscape JPEG with a red block filling its top-left corner. The
     * block is large compared to the probe inset, so JPEG blur at its edges
     * does not matter.
     */
    private function makeMarkedJpeg(): string
    {
        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagefilledrectangle($img, 0, 0, self::WIDTH, self::HEIGHT, imagecolorallocate($img, 0, 0, 255));
        imagefilledrectangle($img, 0, 0, 14, 14, imagecolorallocate($img, 255, 0, 0));

        ob_start();
        imagejpeg($img, null, 95);
        return ob_get_clean();
    }

    /**
     * Insert an APP1 EXIF segment holding only an IFD0 Orientation tag right
     * after the SOI marker.
     */
    private function withExifOrientation(string $jpeg, int $orientation): string
    {
        $payload = "Exif\x00\x00" . TiffBuilder::withOrientation($orientation);
        $app1 = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

        return "\xFF\xD8" . $app1 . substr($jpeg, 2);
    }

    /**
     * Assert the result is the marked fixture turned so that its red marker
     * sits in $corner, with width and height swapped if $swapped.
     */
    private function assertUpright(string $jpeg, string $corner, bool $swapped): void
    {
        $img = imagecreatefromstring($jpeg);
        $this->assertNotFalse($img, 'Result is not a decodable image');

        $width = imagesx($img);
        $height = imagesy($img);
        $this->assertSame($swapped ? [self::HEIGHT, self::WIDTH] : [self::WIDTH, self::HEIGHT], [$width, $height]);

        $inset = 4;
        $probes = [
            'top-left' => [$inset, $inset],
            'top-right' => [$width - 1 - $inset, $inset],
            'bottom-left' => [$inset, $height - 1 - $inset],
            'bottom-right' => [$width - 1 - $inset, $height - 1 - $inset],
        ];
        foreach ($probes as $name => [$x, $y]) {
            $rgb = imagecolorat($img, $x, $y);
            $isRed = (($rgb >> 16) & 0xFF) > 200 && ($rgb & 0xFF) < 80;
            $this->assertSame($name === $corner, $isRed, "Unexpected colour in the $name corner");
        }
    }
}
