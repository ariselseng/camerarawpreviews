<?php

define('PHPUNIT_RUN', 1);
require_once __DIR__.'/../../../lib/base.php';
setLocale(LC_ALL, 'C');
setLocale(LC_CTYPE, 'C');

\OC_App::loadApp('camerarawpreviews');

OC_Hook::clear();
