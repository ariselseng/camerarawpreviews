<?php

namespace OCA\CameraRawPreviews\Tests;

use OCA\CameraRawPreviews\Preview\Support\RawCliRenderer;
use PHPUnit\Framework\TestCase;

if (!class_exists(RawCliRenderer::class)) {
    require_once __DIR__ . '/../lib/Preview/Support/RawCliRenderer.php';
}

/**
 * Tests for RawCliRenderer using the real rs-fallback binary.
 *
 * The fixture (RAW_KODAK_DC50.KDC, 91K) has no embedded JPEG, so the
 * pure-PHP pipeline always returns null for it. Any JPEG we get back here
 * came exclusively from the binary via libraw — that's exactly what we want
 * to verify.
 *
 * The binary is found via the CRP_CLI_PATH env var pointing at the bundled
 * bin/ relative to this file, so the test runs without Nextcloud.
 */
class RawCliRendererTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/RAW_KODAK_DC50.KDC';
    private string|false $savedEnv;

    protected function setUp(): void
    {
        $this->savedEnv = getenv('CRP_CLI_PATH');

        $bin = __DIR__ . '/../bin/rs-fallback-linux-' . $this->hostArch();
        if (!is_executable($bin)) {
            $this->markTestSkipped("Binary not found at $bin — run rs-fallback/build.sh first.");
        }
        putenv('CRP_CLI_PATH=' . $bin);
    }

    protected function tearDown(): void
    {
        if ($this->savedEnv === false) {
            putenv('CRP_CLI_PATH');
        } else {
            putenv('CRP_CLI_PATH=' . $this->savedEnv);
        }
        parent::tearDown();
    }

    public function testRendersJpegFromRawWithNoEmbeddedPreview(): void
    {
        // KDC has no embedded JPEG — only the binary (libraw) can produce output.
        $result = RawCliRenderer::renderPreview(self::FIXTURE);

        $this->assertNotNull($result, 'Binary returned null for ' . basename(self::FIXTURE));
        $this->assertSame("\xFF\xD8", substr($result, 0, 2), 'Output should start with JPEG SOI');

        $info = getimagesizefromstring($result);
        $this->assertNotFalse($info, 'Output is not a parseable image');
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertGreaterThan(0, $info[0], 'Width should be positive');
        $this->assertGreaterThan(0, $info[1], 'Height should be positive');
    }

    public function testReturnsNullForMissingFile(): void
    {
        $this->assertNull(RawCliRenderer::renderPreview('/no/such/file.kdc'));
    }

    // -------------------------------------------------------------------------

    private function hostArch(): string
    {
        $raw = strtolower(trim((string) php_uname('m')));
        return match ($raw) {
            'x86_64', 'amd64'  => 'x86_64',
            'aarch64', 'arm64' => 'aarch64',
            default            => $raw,
        };
    }
}
