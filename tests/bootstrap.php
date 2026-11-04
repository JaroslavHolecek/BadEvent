<?php

declare(strict_types=1);

// Safety net: without tests/run.sh (or the Docker entrypoint, which calls it) mail() would use the
// system sendmail and the tests' emails would really be sent. Refuse to run at all in that case.
if (!str_contains((string) ini_get('sendmail_path'), 'fake-sendmail')) {
    fwrite(STDERR, "Tests must be started through tests/run.sh — it routes mail() to tests/bin/fake-sendmail.\n"
        . "Current sendmail_path: '" . ini_get('sendmail_path') . "'. Refusing to run, no email may leave the machine.\n");
    exit(1);
}

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
