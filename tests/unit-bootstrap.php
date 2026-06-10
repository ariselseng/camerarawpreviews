<?php

// Minimal bootstrap for the dependency-free unit suite (PreviewExtractorTest).
// Unlike tests/bootstrap.php this does NOT boot Nextcloud, so the unit tests
// can run anywhere PHP + GD is available.
require_once __DIR__ . '/../lib/PreviewExtractor.php';
require_once __DIR__ . '/../lib/Preview/Support/OrientationReader.php';
require_once __DIR__ . '/../lib/Preview/Support/RawCliRenderer.php';
