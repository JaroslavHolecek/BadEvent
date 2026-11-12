<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\View;
use App\Repositories\AuditLogRepository;

/** Audit-log viewer (audit_logs table) — visible only to the 'techadmin' role, not even to administrators. */
final class LogController extends Controller
{
    /** Max rows shown on the screen page / in the print export (protects against huge unfiltered ranges). */
    private const SCREEN_LIMIT = 500;
    private const PRINT_LIMIT  = 5000;

    public function index(Request $request, array $params): void
    {
        $this->requireTechAdmin();

        [$dateFrom, $dateTo] = $this->resolveRange($request);
        $rows      = (new AuditLogRepository())->listByDateRange($dateFrom, $dateTo, self::SCREEN_LIMIT);
        $truncated = count($rows) > self::SCREEN_LIMIT;

        View::renderWithLayout('admin/logs', [
            'title'     => 'Logy',
            'dateFrom'  => $dateFrom,
            'dateTo'    => $dateTo,
            'rows'      => array_slice($rows, 0, self::SCREEN_LIMIT),
            'truncated' => $truncated,
            'limit'     => self::SCREEN_LIMIT,
            'flash'     => $this->getFlash(),
        ]);
    }

    public function print(Request $request, array $params): void
    {
        $this->requireTechAdmin();

        [$dateFrom, $dateTo] = $this->resolveRange($request);
        $rows      = (new AuditLogRepository())->listByDateRange($dateFrom, $dateTo, self::PRINT_LIMIT);
        $truncated = count($rows) > self::PRINT_LIMIT;

        echo View::render('admin/logs-print', [
            'title'     => 'Logy aplikace',
            'dateFrom'  => $dateFrom,
            'dateTo'    => $dateTo,
            'rows'      => array_slice($rows, 0, self::PRINT_LIMIT),
            'truncated' => $truncated,
            'limit'     => self::PRINT_LIMIT,
        ]);
    }

    /**
     * date_from / date_to from the query string ('Y-m-d'; invalid or empty = unbounded in that direction).
     * With no filter submitted at all, defaults to the last 7 days so the page never loads the whole table.
     * Swaps the two when entered in reverse order.
     */
    private function resolveRange(Request $request): array
    {
        $hasFilter = $request->get('date_from', null) !== null || $request->get('date_to', null) !== null;
        if (!$hasFilter) {
            return [date('Y-m-d', strtotime('-7 days')), date('Y-m-d')];
        }

        $from = $this->parseDate((string) $request->get('date_from', ''));
        $to   = $this->parseDate((string) $request->get('date_to', ''));
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        return [$from, $to];
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($d === false || $d->format('Y-m-d') !== $value) {
            return null;
        }
        $year = (int) $d->format('Y');
        return $year >= 2000 && $year <= 2100 ? $value : null;
    }
}
