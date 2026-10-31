<?php

namespace OCA\CameraRawPreviews\Tests;

use OCA\CameraRawPreviews\Preview\Support\JpegValidator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/Preview/Support/JpegValidator.php';

/**
 * Tests for the shared GD decode guard.
 */
class JpegValidatorTest extends TestCase
{
    private string|false $savedMemoryLimit;

    protected function setUp(): void
    {
        $this->savedMemoryLimit = ini_get('memory_limit');
    }

    protected function tearDown(): void
    {
        if ($this->savedMemoryLimit !== false) {
            ini_set('memory_limit', $this->savedMemoryLimit);
        }
        parent::tearDown();
    }

    public function testRejectsZeroAndNegativeDimensions(): void
    {
        $this->assertFalse(JpegValidator::canDecode(0, 100));
        $this->assertFalse(JpegValidator::canDecode(100, 0));
        $this->assertFalse(JpegValidator::canDecode(-1, 100));
    }

    public function testRejectsImagesAboveThePixelCap(): void
    {
        // Bypass the memory half so this asserts the fixed cap only.
        ini_set('memory_limit', '-1');

        $this->assertFalse(JpegValidator::canDecode(65535, 65535));
        $this->assertFalse(JpegValidator::canDecode(8001, 8000)); // 64,008,000 px
        $this->assertTrue(JpegValidator::canDecode(8000, 8000));  // exactly 64 MP
        $this->assertTrue(JpegValidator::canDecode(4096, 4096));
    }

    public function testRespectsTheMemoryLimit(): void
    {
        // Leave 16 MB of headroom. A 1 MP bitmap needs 4 MB, 16 MP needs 64 MB.
        $limit = memory_get_usage(true) + 16 * 1024 * 1024;
        ini_set('memory_limit', (string)$limit);

        $this->assertTrue(JpegValidator::canDecode(1000, 1000));
        $this->assertFalse(JpegValidator::canDecode(4000, 4000));
    }
}
