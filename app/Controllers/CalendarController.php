<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\View;
use App\Repositories\ActionRepository;
use App\Repositories\BuildingCodeDutyRepository;
use App\Repositories\CalendarRepository;
use App\Services\CalendarService;

final class CalendarController extends Controller
{
    public function places(Request $request, array $params): void
    {
        $this->requireAuth();
        $calendarService = new CalendarService();
        [$year, $month]   = $calendarService->resolveYearMonth($request);

        $repo   = new CalendarRepository();
        $places = $repo->placeOccupancyForMonth($year, $month);
        $places = $this->withQueuePositions($places);

        if ($request->isAjax()) {
            $this->json([
                'year'   => $year,
                'month'  => $month,
                'places' => $places,
            ]);
        }

        $todayDuty = (new BuildingCodeDutyRepository())->forDate(date('Y-m-d'));

        View::renderWithLayout('calendar/places', [
            'title'         => 'Kalendář místností',
            'year'          => $year,
            'month'         => $month,
            'places'        => $places,
            'grid'          => $calendarService->buildDayGrid($year, $month, []),
            'todayDuty'     => $todayDuty,
            'dayNote'       => $todayDuty['note'] ?? null,
            'canManageDuty' => in_array('building_code_duty.manage', $this->allowedOps(), true),
        ]);
    }

    /** Annotates each still-queued (unconfirmed) action with its real, authoritative queue position — same rule as the action edit form's room picker. */
    private function withQueuePositions(array $places): array
    {
        $actionRepo = new ActionRepository();
        foreach ($places as &$place) {
            foreach ($place['actions'] as &$action) {
                $action['queue_position'] = $action['is_queued']
                    ? $actionRepo->queuePosition((int) $action['id'], (int) $place['id'])
                    : null;
            }
            unset($action);
        }
        unset($place);

        return $places;
    }
}
