<?php

/**
 * Daily maintenance run — checks everything that may need a notification (in-app + email).
 *
 * Run once a day from cron (CLI only, it refuses to run over the web), e.g. every day at 6:00:
 *
 *   0 6 * * * /usr/bin/php /web/TEST/BadEvent/cron/daily.php >> /web/TEST/BadEvent/storage/logs/cron.log 2>&1
 *
 * (Adjust the php binary path — `which php` — and the project path to your server.)
 *
 * The notification checks themselves are defined in one place only —
 * NotificationService::runTimeBasedChecks(); this script just runs them (always — having it in
 * crontab is the switch) plus the cleanup below. The same method also runs on "Můj přehled" load
 * per notifications.time_related_checks_dashboard_interval_minutes (<0 never, 0 always,
 * >0 at most once per that many minutes; default 20 h).
 * Every check is idempotent (each notification is recorded as sent), so running this more often,
 * or alongside the dashboard run, never sends anything twice. Every check covers all actions.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Core/helpers.php';

error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../storage/logs/error.log');

$appConfig = App\Core\Config::load('app');
date_default_timezone_set($appConfig['timezone']);

$failed = 0;
$report = static function (string $name, ?Throwable $error, float $seconds) use (&$failed): void {
    if ($error === null) {
        printf("[%s] OK    %s (%.2f s)\n", date('Y-m-d H:i:s'), $name, $seconds);
        return;
    }
    $failed++;
    printf("[%s] CHYBA %s: %s\n", date('Y-m-d H:i:s'), $name, $error->getMessage());
    error_log('cron/daily.php — ' . $name . ' failed: ' . $error);
};

// All notification checks — the list lives in NotificationService::runTimeBasedChecks().
try {
    foreach ((new App\Services\NotificationService())->runTimeBasedChecks() as $name => $result) {
        $report($name, $result['error'], $result['seconds']);
    }
} catch (Throwable $e) {
    $report('Upozornění', $e, 0.0); // e.g. no database connection at all
}

// Not a notification: drops building-code-duty rows (and their day notes) older than 2 months — runs in the constructor.
$started = microtime(true);
try {
    new App\Repositories\BuildingCodeDutyRepository();
    $report('Úklid kódování budovy', null, microtime(true) - $started);
} catch (Throwable $e) {
    $report('Úklid kódování budovy', $e, microtime(true) - $started);
}

exit($failed > 0 ? 1 : 0);
