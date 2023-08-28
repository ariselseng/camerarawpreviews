<?php

//define('PHPUNIT_RUN', 1);
require_once __DIR__ . '/../../../tests/bootstrap.php';
setLocale(LC_ALL, 'C');
setLocale(LC_CTYPE, 'C');

\OC_App::loadApp('camerarawpreviews');

OC_Hook::clear();
