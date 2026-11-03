<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\View;
use App\Repositories\BuildingCodeDutyRepository;
use App\Repositories\UserRepository;
use App\Services\AuditLogService;

final class BuildingCodeDutyController extends Controller
{
    public function admin(Request $request, array $params): void
    {
        $this->requirePermission('building_code_duty.manage');

        [$year, $month] = $this->resolveYearMonth($request);
        [$minYear, $minMonth, $maxYear, $maxMonth] = $this->allowedRange();

        // Clamp navigation to the allowed 2-months-back/2-months-forward window.
        if ($this->monthIndex($year, $month) < $this->monthIndex($minYear, $minMonth)) {
            [$year, $month] = [$minYear, $minMonth];
        }
        if ($this->monthIndex($year, $month) > $this->monthIndex($maxYear, $maxMonth)) {
            [$year, $month] = [$maxYear, $maxMonth];
        }

        $daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to   = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

        $byDate = [];
        foreach ((new BuildingCodeDutyRepository())->listRange($from, $to) as $row) {
            $byDate[$row['duty_date']] = $row;
        }

        View::renderWithLayout('admin/building-code-duty', [
            'title'       => 'Kódování budovy a poznámka ke dni',
            'year'        => $year,
            'month'       => $month,
            'daysInMonth' => $daysInMonth,
            'byDate'      => $byDate,
            'users'       => (new UserRepository())->listActive(),
            'canPrev'     => $this->monthIndex($year, $month) > $this->monthIndex($minYear, $minMonth),
            'canNext'     => $this->monthIndex($year, $month) < $this->monthIndex($maxYear, $maxMonth),
            'flash'       => $this->getFlash(),
        ]);
    }

    public function save(Request $request, array $params): void
    {
        $this->requirePermission('building_code_duty.manage');

        $year       = (int) $request->post('year');
        $month      = (int) $request->post('month');
        $primary    = (array) $request->post('primary_user_id', []);
        $substitute = (array) $request->post('substitute_user_id', []);
        $notes      = (array) $request->post('note', []);

        $repo = new BuildingCodeDutyRepository();
        foreach ($primary as $date => $userId) {
            $primaryId    = (int) $userId > 0 ? (int) $userId : null;
            $substituteId = (int) ($substitute[$date] ?? 0) > 0 ? (int) $substitute[$date] : null;
            $note         = trim((string) ($notes[$date] ?? ''));
            $repo->set((string) $date, $primaryId, $substituteId, $note !== '' ? $note : null);
        }

        (new AuditLogService())->log(Auth::userId(), 'building_code_duty', 0, 'update', null, ['year' => $year, 'month' => $month]);

        $this->flashSuccess('Kódování budovy a poznámky ke dnům byly uloženy.');
        $this->redirect('/admin/building-code-duty?year=' . $year . '&month=' . $month);
    }

    /** @return array{0:int,1:int} [year, month] from GET, defaulting to the current month. */
    private function resolveYearMonth(Request $request): array
    {
        $year  = (int) $request->get('year', (int) date('Y'));
        $month = (int) $request->get('month', (int) date('n'));
        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }
        return [$year, $month];
    }

    /** @return array{0:int,1:int,2:int,3:int} [minYear, minMonth, maxYear, maxMonth] */
    private function allowedRange(): array
    {
        $min = strtotime('-2 months');
        $max = strtotime('+2 months');
        return [(int) date('Y', $min), (int) date('n', $min), (int) date('Y', $max), (int) date('n', $max)];
    }

    private function monthIndex(int $year, int $month): int
    {
        return $year * 12 + $month;
    }
}
