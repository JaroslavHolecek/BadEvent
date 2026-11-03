<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Sends the app's own (non-login) emails via PHP mail(), with the sender from .envConfig/app.php —
 * same mechanism BadLogin uses for login links, plus properly encoded UTF-8 subject/sender name.
 */
final class MailService
{
    private array $config;

    public function __construct()
    {
        $this->config = \App\Core\Config::load('app');
    }

    /** Absolute URL of an app route (for links in emails, where APP_BASE-relative paths don't work). */
    public function absoluteUrl(string $path): string
    {
        return rtrim((string) ($this->config['base_url'] ?? ''), '/') . $path;
    }

    /**
     * Sends an HTML email. Never throws — a mail failure must not break the action that triggered
     * it (the in-app notification is already stored); returns false instead.
     */
    public function send(string $to, string $subject, string $htmlBody): bool
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $sanitize = static fn(string $v): string => str_replace(["\r", "\n"], '', $v);
        $from     = $sanitize((string) ($this->config['mail_from'] ?? ''));
        $fromName = $sanitize((string) ($this->config['email_from_name'] ?? ''));

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
        ];
        if ($from !== '') {
            $headers[] = 'From: ' . ($fromName !== '' ? mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $from . '>' : $from);
        }

        try {
            return @mail($sanitize($to), mb_encode_mimeheader($sanitize($subject), 'UTF-8'), $htmlBody, implode("\r\n", $headers));
        } catch (\Throwable) {
            return false;
        }
    }
}
