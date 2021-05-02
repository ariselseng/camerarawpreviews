<?php

use \OCA\CameraRawPreviews\AppInfo\Application;

$app = \OC::$server->query(Application::class);
$app->register();