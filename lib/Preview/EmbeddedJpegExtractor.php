<?php

namespace OCA\CameraRawPreviews\Preview;

use OCA\CameraRawPreviews\Preview\Support\FileReader;
use OCA\CameraRawPreviews\Preview\Support\JpegValidator;

/**
 * Extracts the highest-resolution embedded JPEG from a container file by
 * scanning for JPEG blobs (FF D8 FF … FF D9).
 *
 * This covers virtually every camera RAW format (CR2, NEF, ARW, DNG, RW2,
 * RAF, …) which embed one or more JPEG previews. Each candidate is validated
 * and measured with GD; the largest fully-decodable one is returned.
 */
class EmbeddedJpegExtractor implements FormatExtractor
{
    private const START_MARKER = "\xFF\xD8\xFF";
    private const CHUNK_SIZE = 1024 * 1024;
    private const MIN_JPEG_SIZE = 100;

    /**
     * Bounds for scanning an untrusted container. A real RAW carries a handful
     * of previews; these limits only bite on files crafted to make the scan
     * expensive, and then the extractor simply reports "no preview".
     */
    private const MAX_STARTS = 4096;
    private const MAX_CANDIDATE_BYTES = 64 * 1024 * 1024;
    /** Total candidate bytes read from the file during one scan. */
    private const MAX_SCAN_BYTES = 256 * 1024 * 1024;
    /** Largest candidates retained for the decodability check at the end. */
    private const MAX_KEPT_CANDIDATES = 4;
    private const MAX_KEPT_BYTES = 96 * 1024 * 1024;
    /** Marker segments walked before giving up on one candidate. */
    private const MAX_MARKER_STEPS = 4096;
    /** Marker segments walked across the whole scan. */
    private const MAX_TOTAL_MARKER_STEPS = 65536;

    /**
     * Largest image handed to GD for the decodability check. The largest
     * real-world full-size previews are around 45-50 MP (Canon R5, Nikon Z9);
     * 64 MP leaves room for that without letting a crafted header claim
     * 65535x65535 pixels and make GD try to allocate tens of gigabytes.
     */
    private const MAX_DECODE_PIXELS = 64_000_000;
    /** GD truecolour images need four bytes per pixel. */
    private const GD_BYTES_PER_PIXEL = 4;

    /**
     * Per-reader results, so one extraction pass that consults the embedded
     * JPEG both for comparison and as the fallback result scans and decodes it
     * only once. A WeakMap keeps the cache tied to the reader's lifetime; the
     * array wrapper lets null ("no embedded JPEG") be cached too.
     *
     * The cache assumes the file behind a reader does not change while the
     * reader is alive; PreviewExtractor creates one reader per extraction, so
     * this holds.
     *
     * @var \WeakMap<FileReader, array{0: string|null}>|null
     */
    private static ?\WeakMap $results = null;

    /**
     * Always worth attempting — the scan simply finds nothing on files without
     * an embedded JPEG, and the caller falls through to the next extractor.
     */
    public function supports(FileReader $reader): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'embedded JPEG';
    }

    /**
     * Embedded previews are returned verbatim; orientation is applied by
     * PreviewExtractor from the preview's or container's orientation tag.
     */
    public function appliesOrientation(): bool
    {
        return false;
    }

    public function extract(FileReader $reader): ?string
    {
        self::$results ??= new \WeakMap();
        if (self::$results->offsetExists($reader)) {
            return self::$results[$reader][0];
        }

        $result = $this->scan($reader);
        self::$results[$reader] = [$result];

        return $result;
    }

    /**
     * Scan the file for the largest decodable embedded JPEG.
     *
     * Only a few candidates are retained at a time, so a container full of
     * valid-looking JPEGs cannot make the scan hold an unbounded amount of
     * data. The survivors are checked with GD in descending resolution order,
     * preserving the fallback to the next candidate when one is corrupt.
     */
    private function scan(FileReader $reader): ?string
    {
        $starts = $reader->findAll(self::START_MARKER, self::MAX_STARTS);
        if (empty($starts)) {
            return null;
        }

        $candidates = [];
        $keptBytes = 0;
        $scanBudget = self::MAX_SCAN_BYTES;
        $stepsLeft = self::MAX_TOTAL_MARKER_STEPS;

        foreach ($starts as $start) {
            if ($stepsLeft <= 0) {
                break; // marker-walk budget spent: stop scanning this file
            }
            $end = $this->findJpegEnd($reader, $start, $stepsLeft);
            if ($end === null) {
                continue;
            }

            $size = $end - $start;
            if ($size <= self::MIN_JPEG_SIZE || $size > self::MAX_CANDIDATE_BYTES) {
                continue;
            }
            if ($size > $scanBudget) {
                break; // total read budget spent: stop scanning this file
            }

            $data = $reader->readBytes($start, $size);
            if ($data === null) {
                continue;
            }
            $scanBudget -= $size;

            $dimensions = JpegValidator::getResolution($data);
            if ($dimensions === null) {
                continue;
            }

            $pixels = $dimensions[0] * $dimensions[1];
            // Never ask GD to allocate more than the dimensions and the current
            // memory limit allow; a crafted header could otherwise OOM PHP.
            if (!$this->canDecode($pixels)) {
                continue;
            }

            $candidates[] = [
                'data' => $data,
                'pixels' => $pixels,
                'size' => $size,
            ];
            $keptBytes += $size;

            // Highest resolution first; drop the smallest until both caps hold.
            usort($candidates, static fn($a, $b) => $b['pixels'] <=> $a['pixels']);
            while (
                (count($candidates) > self::MAX_KEPT_CANDIDATES || $keptBytes > self::MAX_KEPT_BYTES)
                && $candidates !== []
            ) {
                $dropped = array_pop($candidates);
                $keptBytes -= $dropped['size'];
            }
        }

        foreach ($candidates as $candidate) {
            if (JpegValidator::isDecodable($candidate['data'])) {
                return $candidate['data'];
            }
        }

        return null;
    }

    /**
     * Whether GD can plausibly decode an image of this many pixels without
     * exceeding the PHP memory limit.
     */
    private function canDecode(int $pixels): bool
    {
        if ($pixels <= 0 || $pixels > self::MAX_DECODE_PIXELS) {
            return false;
        }

        $limit = $this->memoryLimitBytes();
        if ($limit === null) {
            return true; // no limit configured
        }

        $available = $limit - memory_get_usage(true);
        return $available > 0 && $pixels <= intdiv($available, self::GD_BYTES_PER_PIXEL);
    }

    /**
     * The configured memory_limit in bytes, or null when unlimited/unknown.
     */
    private function memoryLimitBytes(): ?int
    {
        $raw = trim((string)ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $value = (int)$raw;
        if ($value <= 0) {
            return null;
        }

        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Find the end of the JPEG starting at $start by walking its marker
     * segments. Application segments (e.g. an EXIF APP1 carrying a thumbnail)
     * are skipped via their length field, so a nested thumbnail's FF D9 is
     * never mistaken for the outer image's end.
     *
     * @return int|null Absolute offset just past the EOI (FF D9), or null.
     */
    private function findJpegEnd(FileReader $reader, int $start, int &$stepsLeft): ?int
    {
        if ($reader->readAt($start, 2) !== "\xFF\xD8") {
            return null;
        }

        $fileSize = $reader->size();
        // A candidate larger than the cap is rejected anyway; stopping the walk
        // here also bounds the work spent on a crafted marker chain.
        $limit = min($fileSize, $start + self::MAX_CANDIDATE_BYTES);
        $pos = $start + 2;
        $steps = 0;

        while ($pos < $limit) {
            if (++$steps > self::MAX_MARKER_STEPS || --$stepsLeft < 0) {
                return null;
            }
            $header = $reader->readAt($pos, 2);
            if ($header === null || strlen($header) < 2 || $header[0] !== "\xFF") {
                return null;
            }

            $marker = ord($header[1]);

            // Fill byte: skip a single 0xFF and re-read.
            if ($marker === 0xFF) {
                $pos += 1;
                continue;
            }

            // EOI.
            if ($marker === 0xD9) {
                return $pos + 2;
            }

            // Standalone markers without a length payload (RSTn, TEM).
            if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                $pos += 2;
                continue;
            }

            // All remaining markers carry a 2-byte length.
            $lengthBytes = $reader->readAt($pos + 2, 2);
            if ($lengthBytes === null || strlen($lengthBytes) < 2) {
                return null;
            }
            $length = (ord($lengthBytes[0]) << 8) | ord($lengthBytes[1]);
            $segmentEnd = $pos + 2 + $length;

            // Start of Scan: entropy-coded data of unknown length follows.
            if ($marker === 0xDA) {
                $next = $this->scanNextMarker($reader, $segmentEnd, $limit);
                if ($next === null) {
                    return null;
                }
                $pos = $next;
                continue;
            }

            $pos = $segmentEnd;
        }

        return null;
    }

    /**
     * Scan entropy-coded data for the next real marker, skipping stuffed bytes
     * (FF 00) and restart markers (FF D0-D7).
     */
    private function scanNextMarker(FileReader $reader, int $from, int $limit): ?int
    {
        $limit = min($limit, $reader->size());
        $pos = $from;
        $carry = '';

        while ($pos < $limit) {
            $chunk = $reader->readAt($pos, min(self::CHUNK_SIZE, $limit - $pos));
            if ($chunk === null || $chunk === '') {
                break;
            }

            $buffer = $carry . $chunk;
            $base = $pos - strlen($carry);
            $len = strlen($buffer);

            for ($i = 0; $i < $len - 1; $i++) {
                if ($buffer[$i] !== "\xFF") {
                    continue;
                }
                $next = ord($buffer[$i + 1]);
                if ($next !== 0x00 && !($next >= 0xD0 && $next <= 0xD7)) {
                    return $base + $i;
                }
            }

            // Carry the trailing byte in case an FF sits on the chunk boundary.
            $carry = substr($buffer, -1);
            $pos += strlen($chunk);
        }

        return null;
    }
}
