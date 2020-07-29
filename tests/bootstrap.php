<?php

define('PHPUNIT_RUN', 1);
require_once __DIR__.'/../../../lib/base.php';

\OC_App::loadApp('camerarawpreviews');

OC_Hook::clear();
