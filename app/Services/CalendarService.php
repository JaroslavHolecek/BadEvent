<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Request;

final class CalendarService
{
    /** Resolves year/month from query string or defaults to current month. */
    public function resolveYearMonth(Request $request): array
    {
        $year  = (int) ($request->get('year',  date('Y')));
        $month = (int) ($request->get('month', date('n')));
        $year  = max(2000, min(2100, $year));
        $month = max(1,    min(12,   $month));
        return [$year, $month];
    }

    /**
     * Builds a 6-row × 7-col day grid for the month.
     * Each cell: ['date' => 'YYYY-MM-DD', 'day' => int, 'otherMonth' => bool, 'actions' => []]
     */
    public function buildDayGrid(int $year, int $month, array $actions): array
    {
        $firstDay    = (int) date('N', mktime(0, 0, 0, $month, 1, $year)); // 1=Mon
        $daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));

        // Index actions by date
        $byDate = [];
        foreach ($actions as $action) {
            if ($action['public_start_at']) {
                $date = substr($action['public_start_at'], 0, 10);
                $byDate[$date][] = $action;
            }
        }

        $grid     = [];
        $cursor   = mktime(0, 0, 0, $month, 1 - ($firstDay - 1), $year);

        for ($row = 0; $row < 6; $row++) {
            $week = [];
            for ($col = 0; $col < 7; $col++) {
                $date    = date('Y-m-d', $cursor);
                $dayNum  = (int) date('j', $cursor);
                $dayMonth = (int) date('n', $cursor);
                $week[]  = [
                    'date'       => $date,
                    'day'        => $dayNum,
                    'otherMonth' => $dayMonth !== $month,
                    'isToday'    => $date === date('Y-m-d'),
                    'actions'    => $byDate[$date] ?? [],
                ];
                $cursor  = strtotime('+1 day', $cursor);
            }
            $grid[] = $week;
        }

        return $grid;
    }
}
