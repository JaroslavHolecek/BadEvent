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

        $monthFirst = sprintf('%04d-%02d-01', $year, $month);
        $monthLast  = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

        // Full entry on the start day; on every later day of a multi-day action (up to its public end)
        // only a "continuing" marker. Both only within the displayed month (cells of the neighbouring
        // months stay empty, as before) — which also keeps the loop short even for a mistyped end year.
        $byDate     = [];
        $continuing = [];
        foreach ($actions as $action) {
            if (!$action['public_start_at']) {
                continue;
            }
            $start = substr($action['public_start_at'], 0, 10);
            if ($start >= $monthFirst && $start <= $monthLast) {
                $byDate[$start][] = $action;
            }

            $end    = substr((string) ($action['public_end_at'] ?: $action['public_start_at']), 0, 10);
            $cursor = max(date('Y-m-d', strtotime($start . ' +1 day')), $monthFirst);
            $last   = min($end, $monthLast);
            while ($cursor <= $last) {
                $continuing[$cursor][] = $action;
                $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
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
                    'continuing' => $continuing[$date] ?? [], // begun on an earlier day, still running

                ];
                $cursor  = strtotime('+1 day', $cursor);
            }
            $grid[] = $week;
        }

        return $grid;
    }
}
