<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';    // PHPUnit + Tests\ namespace (tests/composer.json)
require __DIR__ . '/../vendor/autoload.php'; // the application (App\ namespace)
require_once __DIR__ . '/../app/Core/helpers.php';

// The app reads its config from tests/.envConfig/ instead of .envConfig/ — also inherited by the
// built-in web server and the cron subprocess the tests start.
if (getenv('BADEVENT_CONFIG_DIR') === false) {
    putenv('BADEVENT_CONFIG_DIR=' . __DIR__ . '/.envConfig');
}

date_default_timezone_set(App\Core\Config::load('app')['timezone']);

if (!defined('APP_BASE')) {
    define('APP_BASE', '');
}

foreach (['tmp/mail', 'tmp/sessions'] as $dir) {
    if (!is_dir(__DIR__ . '/' . $dir)) {
        mkdir(__DIR__ . '/' . $dir, 0777, true);
    }
}
