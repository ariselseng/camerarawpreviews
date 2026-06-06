<?php

namespace OCA\CameraRawPreviews\Preview;

use OCA\CameraRawPreviews\Preview\Support\FileReader;
use OCA\CameraRawPreviews\Preview\Support\JpegValidator;

/**
 * Extracts the page preview from an Adobe InDesign (.indd) document.
 *
 * InDesign stores the preview as a base64-encoded JPEG inside an XMP packet,
 * within <xmpGImg:image> elements (one per page, in page order). The base64 is
 * line-wrapped using XML character references (&#xA;), and because .indd is an
 * object database the same stream can appear several times — some copies padded
 * with binary/null bytes from page fragmentation. We return the first copy that
 * decodes cleanly.
 */
class InDesignExtractor implements FormatExtractor
{
    /** 16-byte master-page GUID every InDesign document begins with. */
    private const GUID = "\x06\x06\xED\xF5\xD8\x1D\x46\xE5\xBD\x31\xEF\xE7\xFE\x74\xB7\x1D";

    private const IMG_OPEN = '<xmpGImg:image>';
    private const IMG_CLOSE = '</xmpGImg:image>';

    /** Sanity guards. */
    private const MAX_IMG_BYTES = 33554432; // 32MB of base64
    private const MAX_BLOCKS = 50;

    public function supports(FileReader $reader): bool
    {
        return $reader->readAt(0, 16) === self::GUID;
    }

    public function name(): string
    {
        return 'InDesign page image';
    }

    /**
     * The XMP page image is a normal, upright JPEG; no orientation tag is in
     * play, so PreviewExtractor's orientation pass is a harmless no-op anyway.
     */
    public function appliesOrientation(): bool
    {
        return false;
    }

    public function extract(FileReader $reader): ?string
    {
        $searchFrom = 0;

        for ($block = 0; $block < self::MAX_BLOCKS; $block++) {
            $tagPos = $reader->findFirst(self::IMG_OPEN, $searchFrom);
            if ($tagPos === null) {
                break;
            }

            $contentStart = $tagPos + strlen(self::IMG_OPEN);
            $closePos = $reader->findFirst(self::IMG_CLOSE, $contentStart);
            if ($closePos === null) {
                break;
            }

            $searchFrom = $closePos + strlen(self::IMG_CLOSE);

            $length = $closePos - $contentStart;
            if ($length <= 0 || $length > self::MAX_IMG_BYTES) {
                continue;
            }

            $encoded = $reader->readBytes($contentStart, $length);
            if ($encoded === null) {
                continue;
            }

            $jpeg = $this->decodeBase64Image($encoded);
            if ($jpeg === null) {
                continue;
            }

            if (JpegValidator::getResolution($jpeg) !== null && JpegValidator::isDecodable($jpeg)) {
                return $jpeg;
            }
        }

        return null;
    }

    /**
     * Decode the base64 payload of an <xmpGImg:image> element.
     *
     * The "x"/"A" in XML entities like &#xA; are valid base64 characters, so
     * the entities must be decoded *before* whitespace is stripped. Strict
     * base64 decoding then rejects any leftover binary (from a fragmented,
     * null-padded copy), letting the caller fall through to a clean copy.
     */
    private function decodeBase64Image(string $encoded): ?string
    {
        $decoded = html_entity_decode($encoded, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $jpeg = base64_decode(preg_replace('/\s+/', '', $decoded), true);
        if ($jpeg === false || $jpeg === '') {
            return null;
        }
        return $jpeg;
    }
}
