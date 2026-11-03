<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\ActionRepository;
use PDO;
use RuntimeException;

/**
 * Handles all allowed action status transitions and enforces business rules.
 *
 * Forward transitions:
 *   draft       → confirmed | not_agreed
 *   confirmed   → completed | cancelled
 *   completed   → closed
 *
 * Every status can also always move one step back to whichever status led into it
 * (e.g. confirmed → draft, completed → confirmed, closed → completed, not_agreed →
 * draft, cancelled → confirmed) — see previousStatus().
 */
final class ActionWorkflowService
{
    private PDO $pdo;
    private ActionRepository $actions;
    private NotificationService $notifications;
    private AuditLogService $auditLog;

    private const TRANSITIONS = [
        'draft'     => ['confirmed', 'not_agreed'],
        'confirmed' => ['completed', 'cancelled'],
        'completed' => ['closed'],
    ];

    public function __construct()
    {
        $this->pdo           = Database::connection();
        $this->actions       = new ActionRepository();
        $this->notifications = new NotificationService();
        $this->auditLog      = new AuditLogService();
    }

    /** Returns the status that transitions forward into $code, if any — i.e. the "one step back" target. */
    public function previousStatus(string $code): ?string
    {
        foreach (self::TRANSITIONS as $from => $tos) {
            if (in_array($code, $tos, true)) {
                return $from;
            }
        }
        return null;
    }

    /** True when $targetCode is $currentCode's "one step back" target (see previousStatus()). */
    public function isReverseTransition(string $currentCode, string $targetCode): bool
    {
        return $this->previousStatus($currentCode) === $targetCode;
    }

    /** Statuses reachable from $code: forward transitions plus stepping one step back. */
    public function allowedTransitions(string $code): array
    {
        $forward = self::TRANSITIONS[$code] ?? [];
        $back    = $this->previousStatus($code);
        return $back !== null ? [...$forward, $back] : $forward;
    }

    /**
     * Required-data guard messages for a transition, or [] if satisfied. Used both to block the
     * transition for regular users and to build the admin's "missing data" warning popup.
     */
    public function missingRequirements(int $actionId, string $currentCode, string $targetStatusCode): array
    {
        $missing = [];
        if ($currentCode === 'draft' && $targetStatusCode === 'confirmed') {
            if (!$this->actions->hasConfirmedPlace($actionId)) {
                $missing[] = 'Akce nemá žádnou zabranou místnost.';
            }
        }
        if ($currentCode === 'confirmed' && $targetStatusCode === 'completed') {
            if (!$this->actions->hasAttendanceRecorded($actionId)) {
                $missing[] = 'Chybí vyplněná docházka.';
            }
        }
        if ($currentCode === 'completed' && $targetStatusCode === 'closed') {
            if (!$this->actions->hasEconomicDataRecorded($actionId)) {
                $missing[] = 'Chybí vyplněné ekonomické údaje (žádný příjem ani náklad).';
            }
        }
        return $missing;
    }

    /**
     * $force lets an administrator push through a transition despite missing required data (the
     * caller is responsible for confirming this with the admin first, e.g. via a warning popup
     * built from missingRequirements()) — regular users can never pass $force=true; that check
     * happens in the controller before calling here.
     */
    public function transition(int $actionId, string $targetStatusCode, int $byUserId, ?string $note = null, bool $force = false): void
    {
        $action = $this->actions->findById($actionId);
        if ($action === null) {
            throw new RuntimeException("Action #{$actionId} not found.");
        }

        $currentCode = $action['status_code'];
        $allowed     = $this->allowedTransitions($currentCode);

        if (!in_array($targetStatusCode, $allowed, true)) {
            throw new RuntimeException(
                "Přechod ze stavu '{$currentCode}' do stavu '{$targetStatusCode}' není povolen."
            );
        }

        if (!$force) {
            $missing = $this->missingRequirements($actionId, $currentCode, $targetStatusCode);
            if (!empty($missing)) {
                throw new RuntimeException(implode(' ', $missing));
            }
        }

        $targetStatusId = $this->resolveStatusId($targetStatusCode);

        $this->pdo->beginTransaction();
        try {
            $sql = <<<SQL
                INSERT INTO action_status_history
                    (action_id, action_status_id, created_by_user_id, note)
                VALUES (:action_id, :status_id, :user_id, :note)
            SQL;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':action_id' => $actionId,
                ':status_id' => $targetStatusId,
                ':user_id'   => $byUserId,
                ':note'      => $note,
            ]);

            // Cancelling or marking an action not-agreed automatically frees up any rooms it
            // was holding — both confirmed and merely-requested — so nothing stays reserved.
            if (in_array($targetStatusCode, ['cancelled', 'not_agreed'], true)) {
                $this->actions->releaseAllPlaces($actionId);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->auditLog->log(
            $byUserId,
            'action',
            $actionId,
            'status_change',
            ['status' => $currentCode],
            ['status' => $targetStatusCode, 'note' => $note]
        );

        $this->notifications->notifyActionStatusChange($actionId, $currentCode, $targetStatusCode, $byUserId);
        (new PlaceQueueService())->reconcile(); // a cancelled/closed action leaves its room queues
    }

    private function resolveStatusId(string $code): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM action_statuses WHERE code = :code');
        $stmt->execute([':code' => $code]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new RuntimeException("Status code '{$code}' not found.");
        }
        return (int) $id;
    }
}
