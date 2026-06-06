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
        $starts = $reader->findAll(self::START_MARKER);
        if (empty($starts)) {
            return null;
        }

        $candidates = [];
        foreach ($starts as $start) {
            $end = $this->findJpegEnd($reader, $start);
            if ($end === null) {
                continue;
            }

            $size = $end - $start;
            if ($size <= self::MIN_JPEG_SIZE) {
                continue;
            }

            $data = $reader->readBytes($start, $size);
            if ($data === null) {
                continue;
            }

            $dimensions = JpegValidator::getResolution($data);
            if ($dimensions === null) {
                continue;
            }

            $candidates[] = [
                'data' => $data,
                'pixels' => $dimensions[0] * $dimensions[1],
            ];
        }

        if (empty($candidates)) {
            return null;
        }

        // Highest resolution first.
        usort($candidates, static fn($a, $b) => $b['pixels'] <=> $a['pixels']);

        foreach ($candidates as $candidate) {
            if (JpegValidator::isDecodable($candidate['data'])) {
                return $candidate['data'];
            }
        }

        return null;
    }

    /**
     * Find the end of the JPEG starting at $start by walking its marker
     * segments. Application segments (e.g. an EXIF APP1 carrying a thumbnail)
     * are skipped via their length field, so a nested thumbnail's FF D9 is
     * never mistaken for the outer image's end.
     *
     * @return int|null Absolute offset just past the EOI (FF D9), or null.
     */
    private function findJpegEnd(FileReader $reader, int $start): ?int
    {
        if ($reader->readAt($start, 2) !== "\xFF\xD8") {
            return null;
        }

        $fileSize = $reader->size();
        $pos = $start + 2;

        while ($pos < $fileSize) {
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
                $next = $this->scanNextMarker($reader, $segmentEnd);
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
    private function scanNextMarker(FileReader $reader, int $from): ?int
    {
        $fileSize = $reader->size();
        $pos = $from;
        $carry = '';

        while ($pos < $fileSize) {
            $chunk = $reader->readAt($pos, self::CHUNK_SIZE);
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
