<?php

namespace OCA\CameraRawPreviews\Preview\Support;

/**
 * Thin, seekable reader around a single file handle.
 *
 * Owns the handle (open it via {@see FileReader::open()} and release it with
 * {@see FileReader::close()}). Provides the byte-level primitives the format
 * extractors share: random reads, exact-length reads, and chunked needle search
 * that tolerates matches straddling chunk boundaries.
 */
class FileReader
{
    private const CHUNK_SIZE = 1024 * 1024; // 1MB

    /** @var resource */
    private $handle;
    private int $size;
    private string $path;

    /**
     * @param resource $handle
     */
    private function __construct($handle, string $path)
    {
        $this->handle = $handle;
        $this->path = $path;

        $current = ftell($handle);
        fseek($handle, 0, SEEK_END);
        $this->size = (int)ftell($handle);
        fseek($handle, $current === false ? 0 : $current, SEEK_SET);
    }

    /**
     * Open a readable file for reading, or return null if it cannot be opened.
     */
    public static function open(string $path): ?self
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        return new self($handle, $path);
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    /**
     * Absolute path of the underlying file (needed by tools like Imagick that
     * read from disk rather than from a handle).
     */
    public function path(): string
    {
        return $this->path;
    }

    public function size(): int
    {
        return $this->size;
    }

    /**
     * Read up to $len bytes from an absolute offset (fewer at EOF).
     */
    public function readAt(int $offset, int $len): ?string
    {
        if (fseek($this->handle, $offset, SEEK_SET) !== 0) {
            return null;
        }
        $data = fread($this->handle, $len);
        return $data === false ? null : $data;
    }

    /**
     * Read exactly $size bytes from an absolute offset, or null if that many
     * bytes are not available.
     */
    public function readBytes(int $offset, int $size): ?string
    {
        if ($size <= 0 || fseek($this->handle, $offset, SEEK_SET) !== 0) {
            return null;
        }

        $data = '';
        $remaining = $size;
        while ($remaining > 0) {
            $chunk = fread($this->handle, min($remaining, self::CHUNK_SIZE));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return strlen($data) === $size ? $data : null;
    }

    /**
     * Find the first occurrence of $needle at or after $from.
     *
     * @return int|null Absolute offset of the match, or null if not found.
     */
    public function findFirst(string $needle, int $from = 0): ?int
    {
        $overlap = max(strlen($needle) - 1, 0);
        if (fseek($this->handle, $from, SEEK_SET) !== 0) {
            return null;
        }

        $bufferOffset = $from;
        $prev = '';
        while ($bufferOffset < $this->size) {
            $chunk = fread($this->handle, self::CHUNK_SIZE);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buffer = $prev . $chunk;
            $pos = strpos($buffer, $needle);
            if ($pos !== false) {
                return $bufferOffset - strlen($prev) + $pos;
            }
            $prev = $overlap > 0 ? substr($buffer, -$overlap) : '';
            $bufferOffset += strlen($chunk);
        }

        return null;
    }

    /**
     * Find every occurrence of $needle in the file.
     *
     * @return int[] Sorted, de-duplicated absolute offsets.
     */
    public function findAll(string $needle): array
    {
        $overlap = max(strlen($needle) - 1, 0);
        $positions = [];

        rewind($this->handle);
        $bufferOffset = 0;
        $prev = '';
        while ($bufferOffset < $this->size) {
            $chunk = fread($this->handle, self::CHUNK_SIZE);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buffer = $prev . $chunk;
            $searchOffset = 0;
            while (($pos = strpos($buffer, $needle, $searchOffset)) !== false) {
                $actual = $bufferOffset - strlen($prev) + $pos;
                if ($actual >= 0) {
                    $positions[$actual] = true; // key-dedupe across overlaps
                }
                $searchOffset = $pos + 1;
            }
            $prev = $overlap > 0 ? substr($buffer, -$overlap) : '';
            $bufferOffset += strlen($chunk);
        }

        $offsets = array_keys($positions);
        sort($offsets);
        return $offsets;
    }
}
