<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\View;
use App\Repositories\ActionRepository;
use App\Repositories\BuildingCodeDutyRepository;
use App\Services\NotificationService;
use App\Services\PermissionService;

final class DashboardController extends Controller
{
    public function index(Request $request, array $params): void
    {
        $this->requireAuth();
        $userId = Auth::userId();

        $actions       = new ActionRepository();
        $notifications = new NotificationService();
        $permissions   = new PermissionService();

        (new \App\Services\PlaceQueueService())->reconcile();
        // Same time-based checks as cron/daily.php — a failing check must never take the dashboard down.
        try {
            $notifications->sendDraftStartReminders();
            $notifications->sendOverdueActionReminders();
            $notifications->sendMissingRoomReminders();
        } catch (\Throwable $e) {
            error_log('Dashboard notification checks failed: ' . $e->getMessage());
        }

        $myActions     = $actions->listForUser($userId);
        $isAdmin       = $permissions->isAdmin($userId);

        // Administrators additionally see everybody's actions that need attention.
        if ($isAdmin) {
            $ownIds = array_map('intval', array_column($myActions, 'id'));
            foreach ($actions->listNeedingAttention(date('Y-m-d H:i:s')) as $extra) {
                if (!in_array((int) $extra['id'], $ownIds, true)) {
                    $myActions[] = $extra;
                }
            }
            usort($myActions, static function (array $x, array $y): int {
                if ($x['public_start_at'] === null || $y['public_start_at'] === null) {
                    return ($x['public_start_at'] === null) <=> ($y['public_start_at'] === null);
                }
                return strtotime($x['public_start_at']) <=> strtotime($y['public_start_at']);
            });
        }

        // Marker: draft/confirmed past their public end (organizer is expected to move the status on);
        // draft within draftSoonDays() of its start (should be confirmed by now);
        // for administrators also every completed action (waiting to be closed).
        $now       = time();
        $soonLimit = strtotime(date('Y-m-d') . ' +' . (ActionRepository::draftSoonDays() + 1) . ' days'); // start date at most draftSoonDays() from today
        foreach ($myActions as &$a) {
            $ended = $a['public_end_at'] !== null && strtotime($a['public_end_at']) < $now;
            $soon  = $a['public_start_at'] !== null && strtotime($a['public_start_at']) < $soonLimit;
            $a['marker'] = match (true) {
                in_array($a['status_code'], ['draft', 'confirmed'], true) && $ended => $a['status_code'],
                $a['status_code'] === 'draft' && $soon                              => 'draft_soon',
                $isAdmin && $a['status_code'] === 'completed'                       => 'completed',
                default                                                             => null,
            };
        }
        unset($a);

        $unread        = $notifications->unreadFor($userId);
        $allowedOps    = $permissions->allowedOperations($userId);

        $todayDuty  = (new BuildingCodeDutyRepository())->forDate(date('Y-m-d'));
        $myDutyRole = null;
        if ($todayDuty !== null) {
            if ((int) $todayDuty['primary_user_id'] === $userId) {
                $myDutyRole = 'primary';
            } elseif ((int) $todayDuty['substitute_user_id'] === $userId) {
                $myDutyRole = 'substitute';
            }
        }

        View::renderWithLayout('dashboard/index', [
            'title'      => 'Můj přehled',
            'myActions'  => $myActions,
            'unread'     => $unread,
            'allowedOps' => $allowedOps,
            'myDutyRole' => $myDutyRole,
            'dayNote'    => $todayDuty['note'] ?? null,
            'flash'      => $this->getFlash(),
        ]);
    }

    public function markNotificationRead(Request $request, array $params): void
    {
        $this->requireAuth();
        $userId = Auth::userId();

        $notifId = (int) ($params['id'] ?? 0);
        if ($notifId > 0) {
            (new NotificationService())->markRead($notifId, $userId);
        }

        if ($request->isAjax()) {
            $this->json(['ok' => true]);
        }
        $this->redirect('/');
    }

    public function markAllNotificationsRead(Request $request, array $params): void
    {
        $this->requireAuth();
        (new NotificationService())->markAllRead(Auth::userId());
        $this->redirect('/');
    }
}
