<?php

// Bootstrap for the integration suite (RawPreviewIProviderV2Test). Boots the
// Nextcloud instance the app is mounted in. Release images ship without the
// server's own tests/ directory, so load lib/base.php directly instead of
// tests/bootstrap.php.
define('PHPUNIT_RUN', 1);
require_once __DIR__ . '/../../../lib/base.php';
setLocale(LC_ALL, 'C');
setLocale(LC_CTYPE, 'C');

\OC_App::loadApp('camerarawpreviews');

OC_Hook::clear();
