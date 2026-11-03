<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\UserRepository;
use PDO;

/**
 * Keeps action_places_desire.first_at_queue_at in sync with the real room queue and raises the
 * related notifications:
 *   - request just reached 1st place (and the room isn't confirmed)  -> organizers/substitutes
 *   - 1st place for 7+ days, room still not confirmed                -> organizers/substitutes
 *   - 1st place for 14+ days, room still not confirmed               -> administrators
 * (7 / 14 are the defaults — see queue_first_notify_days / queue_first_admin_days in NotificationService::setting()).
 * last_notification_at records the last notification for the request and is what stops a
 * threshold notification from being sent twice.
 *
 * "First in queue" uses the same rule as ActionRepository::earlierCompetingRequestsCount(). Only
 * live actions count (Rozjednaná/Potvrzená and not yet ended). Call reconcile() after anything that
 * can change a queue (room requests, action dates, status changes) and on the dashboard load —
 * which also picks up thresholds that pass with time.
 */
final class PlaceQueueService
{
    private PDO $pdo;
    private NotificationService $notifications;

    public function __construct()
    {
        $this->pdo           = Database::connection();
        $this->notifications = new NotificationService();
    }

    public function reconcile(): void
    {
        try {
            $this->doReconcile();
        } catch (\Throwable $e) {
            // A failing queue check must never take the page that triggered it down.
            error_log('PlaceQueueService::reconcile failed: ' . $e->getMessage());
        }
    }

    private function doReconcile(): void
    {
        $sql = <<<SQL
            SELECT * FROM (
                SELECT d.id, d.first_at_queue_at, d.last_notification_at,
                       a1.name AS action_name, pl.name AS place_name, d.action_id,
                       (s1.code IN ('draft', 'confirmed')
                        AND (a1.public_end_at IS NULL OR a1.public_end_at > CURRENT_TIMESTAMP)
                        AND NOT EXISTS (
                            SELECT 1
                            FROM action_places_desire e
                            JOIN actions a2         ON a2.id = e.action_id
                            JOIN action_statuses s2 ON s2.id = a2.actual_status
                            WHERE e.place_id = d.place_id
                              AND e.action_id != d.action_id
                              AND s2.code NOT IN ('not_agreed', 'cancelled')
                              AND e.created_at < d.created_at
                              AND NOT (
                                    COALESCE(a1.public_end_at <= a2.staff_start_at, FALSE)
                                 OR COALESCE(a2.public_end_at <= a1.staff_start_at, FALSE)
                              )
                        )) AS is_first,
                       EXISTS (SELECT 1 FROM action_places ap
                               WHERE ap.action_id = d.action_id AND ap.place_id = d.place_id) AS confirmed,
                       COALESCE(d.first_at_queue_at <= CURRENT_TIMESTAMP - make_interval(days => :week_days)
                                AND (d.last_notification_at IS NULL
                                     OR d.last_notification_at < d.first_at_queue_at + make_interval(days => :week_days)), FALSE) AS week_due,
                       COALESCE(d.first_at_queue_at <= CURRENT_TIMESTAMP - make_interval(days => :admin_days)
                                AND (d.last_notification_at IS NULL
                                     OR d.last_notification_at < d.first_at_queue_at + make_interval(days => :admin_days)), FALSE) AS fortnight_due
                FROM action_places_desire d
                JOIN actions a1         ON a1.id = d.action_id
                JOIN action_statuses s1 ON s1.id = a1.actual_status
                JOIN places pl          ON pl.id = d.place_id
            ) q
            WHERE q.is_first OR q.first_at_queue_at IS NOT NULL
        SQL;
        $weekDays  = (int) NotificationService::setting('queue_first_notify_days');
        $adminDays = (int) NotificationService::setting('queue_first_admin_days');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':week_days' => $weekDays, ':admin_days' => $adminDays]);
        $rows = $stmt->fetchAll();

        $bool = static fn($v): bool => $v === true || $v === 't';

        foreach ($rows as $r) {
            $id        = (int) $r['id'];
            $actionId  = (int) $r['action_id'];
            $isFirst   = $bool($r['is_first']);
            $wasFirst  = $r['first_at_queue_at'] !== null;
            $confirmed = $bool($r['confirmed']);
            $label     = 'Akce "' . $r['action_name'] . '"';
            $room      = 'místnost "' . $r['place_name'] . '"';

            if (!$isFirst) {
                $this->pdo->prepare('UPDATE action_places_desire SET first_at_queue_at = NULL WHERE id = ? AND first_at_queue_at IS NOT NULL')
                    ->execute([$id]);
                continue;
            }

            if (!$wasFirst) {
                $stmt = $this->pdo->prepare('UPDATE action_places_desire SET first_at_queue_at = CURRENT_TIMESTAMP WHERE id = ? AND first_at_queue_at IS NULL');
                $stmt->execute([$id]);
                if ($stmt->rowCount() === 1 && !$confirmed && $this->markNotified($id, $r['last_notification_at'])) {
                    $this->notifyRecipients($actionId, "{$label} je první ve frontě na {$room}. Můžete si ji zabrat.");
                }
                continue;
            }

            $weekDue = $bool($r['week_due']);
            $fortDue = $bool($r['fortnight_due']);
            if ($confirmed || (!$weekDue && !$fortDue) || !$this->markNotified($id, $r['last_notification_at'])) {
                continue;
            }

            if ($weekDue) {
                $this->notifyRecipients($actionId, "{$label} je již {$weekDays} a více dní první ve frontě na {$room} a stále nemá tuto místnost zabranou.");
            }
            if ($fortDue) {
                $text = "{$label} je již {$adminDays} a více dní první ve frontě na {$room} a stále nemá tuto místnost zabranou.";
                foreach ((new UserRepository())->listByRoleCode('administrator') as $admin) {
                    $this->notifications->send((int) $admin['id'], $text, $actionId);
                }
            }
        }
    }

    /**
     * Stamps last_notification_at, but only if it still holds the value this run read — so two
     * simultaneous runs can't both send the same notification. True when this run won.
     */
    private function markNotified(int $desireId, ?string $previous): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE action_places_desire SET last_notification_at = CURRENT_TIMESTAMP
             WHERE id = ? AND last_notification_at IS NOT DISTINCT FROM ?::timestamp'
        );
        $stmt->execute([$desireId, $previous]);
        return $stmt->rowCount() === 1;
    }

    private function notifyRecipients(int $actionId, string $text): void
    {
        foreach ($this->notifications->actionRecipients($actionId) as $userId) {
            $this->notifications->send($userId, $text, $actionId);
        }
    }
}
