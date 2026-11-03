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
 * Every task is idempotent: each notification is recorded as sent, so running this more often,
 * or alongside the same checks triggered on dashboard load, never sends anything twice.
 * The dashboard-load triggers stay in place, so notifications still go out even without cron —
 * cron just makes sure they go out on time when nobody opens the app.
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

$tasks = [
    // Room queue: request reached 1st place, 1st place for 7+ days (organizers), 14+ days (admins).
    'Fronta na místnosti' => fn() => (new App\Services\PlaceQueueService())->reconcile(),

    // Still-draft (Rozjednaná) actions 30 and 7 days before their start (organizers + admins).
    'Blížící se Rozjednané akce' => fn() => (new App\Services\NotificationService())->sendDraftStartReminders(),

    // Rozjednaná/Potvrzená action that already ended (last 30 days) — status should be moved on (organizers + admins).
    'Akce po termínu' => fn() => (new App\Services\NotificationService())->sendOverdueActionReminders(),

    // Rozjednaná/Potvrzená action starting within 7 days with no confirmed room (organizers + admins).
    'Akce bez místnosti' => fn() => (new App\Services\NotificationService())->sendMissingRoomReminders(),

    // Emails whose hand-off failed in the last 2 days get one more try each run.
    'Opakované odeslání emailů' => fn() => (new App\Services\NotificationService())->retryFailedEmails(),

    // Drops building-code-duty rows (and their day notes) older than 2 months — runs in the constructor.
    'Úklid kódování budovy' => fn() => new App\Repositories\BuildingCodeDutyRepository(),
];

$failed = 0;
foreach ($tasks as $name => $task) {
    $started = microtime(true);
    try {
        $task();
        printf("[%s] OK    %s (%.2f s)\n", date('Y-m-d H:i:s'), $name, microtime(true) - $started);
    } catch (Throwable $e) {
        $failed++;
        printf("[%s] CHYBA %s: %s\n", date('Y-m-d H:i:s'), $name, $e->getMessage());
        error_log('cron/daily.php — ' . $name . ' failed: ' . $e);
    }
}

exit($failed > 0 ? 1 : 0);
