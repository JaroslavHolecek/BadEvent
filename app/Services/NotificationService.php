<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class NotificationService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    /**
     * Notification thresholds — the 'notifications' section of .envConfig/app.php; any key missing
     * there (e.g. an older config file on the server) falls back to these defaults.
     */
    private const DEFAULT_SETTINGS = [
        'draft_reminder_days'     => [30, 7], // days before the start of a still-Rozjednaná action
        'overdue_lookback_days'   => 30,      // ended-but-unresolved actions older than this aren't reminded about
        'missing_room_days'       => 7,       // days before the start of an action with no confirmed room
        'queue_first_notify_days' => 7,       // 1st in room queue this long without the room -> organizers
        'queue_first_admin_days'  => 14,      // 1st in room queue this long without the room -> administrators
        'email_retry_days'        => 2,       // failed emails are retried for this many days
    ];

    private static ?array $settings = null;

    public static function setting(string $key): mixed
    {
        if (self::$settings === null) {
            $appConfig      = \App\Core\Config::load('app');
            self::$settings = array_merge(self::DEFAULT_SETTINGS, (array) ($appConfig['notifications'] ?? []));
        }
        return self::$settings[$key];
    }

    /**
     * Stores an in-app notification and also emails it to the user's login email (bl_login.email).
     * email_sent_at is stamped only when the mail was actually handed off, so failed sends stay visible.
     */
    public function send(int $userId, string $text, ?int $relatedActionId = null): void
    {
        $sql = <<<SQL
            INSERT INTO notifications (user_id, text, related_action_id)
            VALUES (:user_id, :text, :action_id)
            RETURNING id
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id'   => $userId,
            ':text'      => $text,
            ':action_id' => $relatedActionId,
        ]);
        $notificationId = (int) $stmt->fetchColumn();

        $this->emailNotification($notificationId, $userId, $text, $relatedActionId);
    }

    /**
     * Emails one stored notification. Stamps email_sent_at on success, email_failed_at on a failed
     * hand-off (so retryFailedEmails() can try again); a user with no usable email stamps nothing.
     */
    private function emailNotification(int $notificationId, int $userId, string $text, ?int $relatedActionId): void
    {
        $email = $this->loginEmail($userId);
        if ($email === null) {
            return;
        }

        $mail = new MailService();
        $body = '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</p>';
        if ($relatedActionId !== null) {
            $link  = htmlspecialchars($mail->absoluteUrl('/actions/' . $relatedActionId), ENT_QUOTES, 'UTF-8');
            $body .= '<p><a href="' . $link . '">Zobrazit akci</a></p>';
        }

        $column = $mail->send($email, 'Upozornění – ' . $this->shortSubject($text), $body) ? 'email_sent_at' : 'email_failed_at';
        $this->pdo->prepare("UPDATE notifications SET {$column} = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':id' => $notificationId]);
    }

    /** Re-sends emails whose hand-off failed in the last email_retry_days days (older ones are left alone — the in-app notification still stands). */
    public function retryFailedEmails(): void
    {
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT id, user_id, text, related_action_id
            FROM notifications
            WHERE email_sent_at IS NULL
              AND email_failed_at IS NOT NULL
              AND created_at > CURRENT_TIMESTAMP - make_interval(days => :days)
            ORDER BY id
        SQL);
        $stmt->execute([':days' => (int) self::setting('email_retry_days')]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $n) {
            $this->emailNotification(
                (int) $n['id'],
                (int) $n['user_id'],
                (string) $n['text'],
                $n['related_action_id'] !== null ? (int) $n['related_action_id'] : null
            );
        }
    }

    /** Login email of a live (not deleted/deactivated) user, or null. */
    private function loginEmail(int $userId): ?string
    {
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT bl.email
            FROM bl_login bl
            JOIN app_users au ON au.id = bl.id
            WHERE bl.id = :id AND bl.deleted_at IS NULL AND au.deleted_at IS NULL AND au.deactivated_at IS NULL
        SQL);
        $stmt->execute([':id' => $userId]);
        $email = $stmt->fetchColumn();
        return $email !== false && $email !== '' ? (string) $email : null;
    }

    /** First ~80 characters of the notification text, for the email subject. */
    private function shortSubject(string $text): string
    {
        $oneLine = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        return mb_strlen($oneLine) > 80 ? mb_substr($oneLine, 0, 77) . '…' : $oneLine;
    }

    public function notifyActionStatusChange(
        int $actionId,
        string $fromCode,
        string $toCode,
        int $byUserId
    ): void {
        $actionName = $this->getActionName($actionId);
        $toLabel    = $this->statusLabel($toCode);

        $text = 'Akce "' . $actionName . '" přešla do stavu: ' . $toLabel . '.';

        foreach ($this->actionRecipients($actionId) as $userId) {
            if ($userId !== $byUserId) {
                $this->send($userId, $text, $actionId);
            }
        }
    }

    /**
     * Reminds organizers + administrators that a draft action is approaching its public start
     * (setting draft_reminder_days, by default 30 and 7 days ahead). Called from cron/daily.php and opportunistically on every dashboard load —
     * action_start_reminders records which reminder was already sent for which start date, so each
     * fires exactly once; if the start date moves, the reminders fire again for the new date.
     * An action that's already within the shorter window gets only that reminder (the longer one is
     * marked as sent without sending), so nobody gets two notifications at once.
     */
    public function sendDraftStartReminders(): void
    {
        $sql = <<<SQL
            SELECT a.id, a.name, a.public_start_at::date AS start_date,
                   a.public_start_at::date - CURRENT_DATE AS days_left
            FROM actions a
            JOIN action_statuses s ON s.id = a.actual_status
            WHERE s.code = 'draft'
              AND a.public_start_at::date >= CURRENT_DATE
              AND a.public_start_at::date - CURRENT_DATE <= :max_days
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $reminderDays = array_map('intval', (array) self::setting('draft_reminder_days'));
        $stmt->execute([':max_days' => max($reminderDays)]);
        $due = $stmt->fetchAll();
        if (empty($due)) {
            return;
        }

        $markSent = $this->pdo->prepare(<<<SQL
            INSERT INTO action_start_reminders (action_id, start_date, days_before)
            VALUES (:action_id, :start_date, :days_before)
            ON CONFLICT DO NOTHING
            RETURNING action_id
        SQL);

        foreach ($due as $a) {
            $daysLeft  = (int) $a['days_left'];
            $threshold = min(array_filter($reminderDays, fn($d) => $d >= $daysLeft));

            $markSent->execute([':action_id' => $a['id'], ':start_date' => $a['start_date'], ':days_before' => $threshold]);
            if ($markSent->fetchColumn() === false) {
                continue; // already sent
            }
            foreach ($reminderDays as $longer) {
                if ($longer > $threshold) {
                    $markSent->execute([':action_id' => $a['id'], ':start_date' => $a['start_date'], ':days_before' => $longer]);
                }
            }

            $text = 'Akce "' . $a['name'] . '" je stále ve stavu Rozjednaná a začíná '
                . date('j. n. Y', strtotime($a['start_date']))
                . ($daysLeft === 0 ? ' (dnes).' : ' (za ' . $daysLeft . ' ' . ($daysLeft === 1 ? 'den' : ($daysLeft <= 4 ? 'dny' : 'dní')) . ').');
            $this->sendToOrganizersAndAdmins((int) $a['id'], $text);
        }
    }

    /**
     * Rozjednaná/Potvrzená action whose public end has passed — its status should have been moved on
     * (Proběhlá / Zrušená / Nedojednaná). One reminder per end date to organizers + administrators.
     * Only actions ended within overdue_lookback_days, so the first run doesn't flood with old ones.
     */
    public function sendOverdueActionReminders(): void
    {
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT a.id, a.name, a.public_end_at, a.public_end_at::date AS ref_date, s.code AS status_code
            FROM actions a
            JOIN action_statuses s ON s.id = a.actual_status
            WHERE s.code IN ('draft', 'confirmed')
              AND a.public_end_at < CURRENT_TIMESTAMP
              AND a.public_end_at::date >= CURRENT_DATE - CAST(:lookback AS integer)
        SQL);
        $stmt->execute([':lookback' => (int) self::setting('overdue_lookback_days')]);

        foreach ($stmt->fetchAll() as $a) {
            if (!$this->markAlertSent((int) $a['id'], 'overdue', $a['ref_date'])) {
                continue;
            }
            $text = 'Akce "' . $a['name'] . '" skončila ' . date('j. n. Y', strtotime($a['public_end_at']))
                . ', ale je stále ve stavu ' . $this->statusLabel($a['status_code']) . '. Změňte prosím její stav.';
            $this->sendToOrganizersAndAdmins((int) $a['id'], $text);
        }
    }

    /** Rozjednaná/Potvrzená action starting within missing_room_days days with no confirmed room. One reminder per start date. */
    public function sendMissingRoomReminders(): void
    {
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT a.id, a.name, a.public_start_at::date AS ref_date
            FROM actions a
            JOIN action_statuses s ON s.id = a.actual_status
            WHERE s.code IN ('draft', 'confirmed')
              AND a.public_start_at::date >= CURRENT_DATE
              AND a.public_start_at::date - CURRENT_DATE <= :days
              AND NOT EXISTS (SELECT 1 FROM action_places ap WHERE ap.action_id = a.id)
        SQL);
        $stmt->execute([':days' => (int) self::setting('missing_room_days')]);

        foreach ($stmt->fetchAll() as $a) {
            if (!$this->markAlertSent((int) $a['id'], 'no_room', $a['ref_date'])) {
                continue;
            }
            $text = 'Akce "' . $a['name'] . '" začíná ' . date('j. n. Y', strtotime($a['ref_date']))
                . ' a nemá zabranou žádnou místnost.';
            $this->sendToOrganizersAndAdmins((int) $a['id'], $text);
        }
    }

    /** Records a one-off alert in action_alerts; true only for the call that actually inserted it (safe against parallel runs). */
    private function markAlertSent(int $actionId, string $alertCode, string $refDate): bool
    {
        $stmt = $this->pdo->prepare(<<<SQL
            INSERT INTO action_alerts (action_id, alert_code, ref_date)
            VALUES (:action_id, :alert_code, :ref_date)
            ON CONFLICT DO NOTHING
            RETURNING action_id
        SQL);
        $stmt->execute([':action_id' => $actionId, ':alert_code' => $alertCode, ':ref_date' => $refDate]);
        return $stmt->fetchColumn() !== false;
    }

    private ?array $adminIds = null;

    private function sendToOrganizersAndAdmins(int $actionId, string $text): void
    {
        $this->adminIds ??= array_map('intval', array_column((new \App\Repositories\UserRepository())->listByRoleCode('administrator'), 'id'));
        foreach (array_unique(array_merge($this->actionOrganizerIds($actionId), $this->adminIds)) as $userId) {
            $this->send($userId, $text, $actionId);
        }
    }

    /** User ids of the action's active organizers (without substitutes). */
    private function actionOrganizerIds(int $actionId): array
    {
        $stmt = $this->pdo->prepare('SELECT user_id FROM action_organizers WHERE action_id = :id AND deactivated_at IS NULL');
        $stmt->execute([':id' => $actionId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** Returns unread notifications for a user, newest first. */
    public function unreadFor(int $userId): array
    {
        $sql = <<<SQL
            SELECT n.*, a.name AS action_name
            FROM notifications n
            LEFT JOIN actions a ON a.id = n.related_action_id
            WHERE n.user_id = :user_id
              AND n.read_at IS NULL
            ORDER BY n.created_at DESC
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function markRead(int $notificationId, int $userId): void
    {
        $sql = <<<SQL
            UPDATE notifications
            SET read_at = CURRENT_TIMESTAMP
            WHERE id = :id AND user_id = :user_id
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
    }

    public function markAllRead(int $userId): void
    {
        $sql = <<<SQL
            UPDATE notifications
            SET read_at = CURRENT_TIMESTAMP
            WHERE user_id = :user_id AND read_at IS NULL
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
    }

    /** User ids of the action's active organizers and substitutes. */
    public function actionRecipients(int $actionId): array
    {
        $sql = <<<SQL
            SELECT user_id FROM action_organizers
            WHERE action_id = :id AND deactivated_at IS NULL
            UNION
            SELECT user_id FROM action_substitutes
            WHERE action_id = :id AND deactivated_at IS NULL
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $actionId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    private function getActionName(int $actionId): string
    {
        $stmt = $this->pdo->prepare('SELECT name FROM actions WHERE id = :id');
        $stmt->execute([':id' => $actionId]);
        return (string) ($stmt->fetchColumn() ?: '#' . $actionId);
    }

    private function statusLabel(string $code): string
    {
        $labels = [
            'draft'      => 'Rozjednaná',
            'not_agreed' => 'Nedojednaná',
            'confirmed'  => 'Potvrzená',
            'completed'  => 'Proběhlá',
            'cancelled'  => 'Zrušená',
            'closed'     => 'Uzavřená',
        ];
        return $labels[$code] ?? $code;
    }
}
