<?php

namespace OCA\CameraRawPreviews\Preview;

use OCA\CameraRawPreviews\Preview\Support\FileReader;

/**
 * A strategy that knows how to pull a JPEG preview out of one family of files.
 *
 * Extractors are tried in order by {@see \OCA\CameraRawPreviews\PreviewExtractor}.
 * The first one whose {@see supports()} returns true and whose {@see extract()}
 * yields non-null bytes wins.
 */
interface FormatExtractor
{
    /**
     * Cheap check (magic bytes etc.) for whether this extractor should run.
     */
    public function supports(FileReader $reader): bool;

    /**
     * Attempt extraction. Returns JPEG bytes, or null if nothing usable.
     */
    public function extract(FileReader $reader): ?string;

    /**
     * Short human-readable label for the technique (used by the CLI/diagnostics).
     */
    public function name(): string;

    /**
     * Whether {@see extract()} already returns an upright image.
     *
     * Most extractors hand back the embedded preview verbatim, so the container
     * may still need its orientation baked in (PreviewExtractor does this). An
     * extractor that renders/orients the image itself (e.g. Imagick's
     * autoOrient) returns true so that pass is skipped — otherwise the image
     * would be double-rotated.
     */
    public function appliesOrientation(): bool;
}
