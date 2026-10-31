<?php

namespace OCA\CameraRawPreviews\Tests;

use OCA\CameraRawPreviews\Preview\Support\FileReader;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/Preview/Support/FileReader.php';

/**
 * Unit tests for the shared byte-level reader primitives.
 */
class FileReaderTest extends TestCase
{
    /** @var string[] */
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

    public function testFindAllReturnsEveryMatchSorted(): void
    {
        $path = $this->writeTmp(str_repeat("ab\xFF\xD8\xFF", 10));

        $offsets = $this->withReader($path, static function (FileReader $reader): array {
            return $reader->findAll("\xFF\xD8\xFF");
        });

        $expected = [];
        for ($i = 0; $i < 10; $i++) {
            $expected[] = $i * 5 + 2;
        }
        $this->assertSame($expected, $offsets);
    }

    public function testFindAllStopsAtLimit(): void
    {
        // Back-to-back matches at 0, 3, 6, …
        $path = $this->writeTmp(str_repeat("\xFF\xD8\xFF", 20));

        $offsets = $this->withReader($path, static function (FileReader $reader): array {
            return $reader->findAll("\xFF\xD8\xFF", 7);
        });

        $this->assertSame([0, 3, 6, 9, 12, 15, 18], $offsets);
    }

    public function testFindAllWithNonPositiveLimitReturnsNothing(): void
    {
        $path = $this->writeTmp(str_repeat("\xFF\xD8\xFF", 5));

        $offsets = $this->withReader($path, static function (FileReader $reader): array {
            return $reader->findAll("\xFF\xD8\xFF", 0);
        });

        $this->assertSame([], $offsets);
    }

    private function writeTmp(string $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crp_reader_');
        file_put_contents($path, $data);
        $this->tmpFiles[] = $path;
        return $path;
    }

    /**
     * @param callable(FileReader): mixed $fn
     */
    private function withReader(string $path, callable $fn): mixed
    {
        $reader = FileReader::open($path);
        $this->assertNotNull($reader, 'Fixture could not be opened');

        try {
            return $fn($reader);
        } finally {
            $reader->close();
        }
    }
}
