<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * PHP's built-in web server serving public/index.php, started once for the whole test run.
 * It inherits BADEVENT_CONFIG_DIR (tests/.envConfig) and the fake sendmail of the test process.
 */
final class HttpServer
{
    /** @var resource|null */
    private static $process = null;

    public static function baseUrl(): string
    {
        return 'http://127.0.0.1:' . self::port();
    }

    private static function port(): int
    {
        return (int) (getenv('TEST_HTTP_PORT') ?: 8765);
    }

    public static function ensureStarted(): void
    {
        if (self::$process !== null) {
            return;
        }

        $root     = dirname(__DIR__, 2);
        $tests    = dirname(__DIR__);
        $sendmail = (string) ini_get('sendmail_path');

        $cmd = [
            PHP_BINARY,
            '-d', 'sendmail_path=' . $sendmail,
            '-d', 'session.save_path=' . $tests . '/tmp/sessions',
            '-S', '127.0.0.1:' . self::port(),
            '-t', $root . '/public',
            $root . '/public/index.php',
        ];
        $log = $tests . '/tmp/http-server.log';
        self::$process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
        if (!is_resource(self::$process)) {
            throw new RuntimeException('Could not start the PHP built-in web server.');
        }

        register_shutdown_function(static function (): void {
            if (is_resource(self::$process)) {
                proc_terminate(self::$process);
                proc_close(self::$process);
            }
        });

        // Wait until it accepts connections.
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', self::port(), $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(50_000);
        }
        throw new RuntimeException('The PHP built-in web server did not start on port ' . self::port() . " (port taken? set TEST_HTTP_PORT). Log: {$log}");
    }
}
