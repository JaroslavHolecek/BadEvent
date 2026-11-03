<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\ActionRepository;
use App\Repositories\EventRepository;
use App\Repositories\FinanceRepository;
use App\Services\AuditLogService;
use App\Services\PermissionService;

final class EventController extends Controller
{
    public function index(Request $request, array $params): void
    {
        $this->requireAuth();
        $repo = new EventRepository();

        $userId         = Auth::userId();
        $canViewArchive = $userId !== null && (new PermissionService())->can($userId, 'archive.view');
        $archive        = $canViewArchive && $request->get('archive') === '1';
        $events         = $archive ? $repo->listAll() : $repo->listActive();

        View::renderWithLayout('events/index', [
            'title'          => $archive ? 'Archiv událostí' : 'Události',
            'events'         => $events,
            'archive'        => $archive,
            'canViewArchive' => $canViewArchive,
            'allowedOps'     => $this->allowedOps(),
            'flash'          => $this->getFlash(),
        ]);
    }

    public function show(Request $request, array $params): void
    {
        $this->requireAuth();
        $id    = (int) $params['id'];
        $repo  = new EventRepository();
        $event = $repo->findById($id);

        if ($event === null) {
            Response::notFound();
        }

        $finance = new FinanceRepository();

        View::renderWithLayout('events/show', [
            'title'                => $event['name'],
            'event'                => $event,
            'actions'              => $repo->getActions($id),
            'incomeTotalsByType'   => $finance->eventIncomeTotalsByType($id),
            'costTotalsByType'     => $finance->eventCostTotalsByType($id),
            'incomeGrandTotals'    => $finance->eventIncomeGrandTotals($id),
            'costGrandTotals'      => $finance->eventCostGrandTotals($id),
            'attendanceTotals'     => (new ActionRepository())->eventAttendanceTotals($id),
            'allowedOps'           => $this->allowedOps(),
            'flash'                => $this->getFlash(),
        ]);
    }

    public function editForm(Request $request, array $params): void
    {
        $this->requirePermission('action.update_basic');
        $repo  = new EventRepository();
        $event = $repo->findById((int) $params['id']);

        if ($event === null) {
            Response::notFound();
        }

        View::renderWithLayout('events/edit', [
            'title'    => 'Upravit: ' . $event['name'],
            'event'    => $event,
            'flash'    => $this->getFlash(),
        ]);
    }

    public function editPost(Request $request, array $params): void
    {
        $this->requirePermission('action.update_basic');
        $id   = (int) $params['id'];
        $name = trim((string) $request->post('name', ''));
        $note = $request->post('note');

        if ($name === '') {
            $this->failForm('Název události je povinný.', '/events/' . $id . '/edit', ['name']);
        }

        (new EventRepository())->update($id, $name, $note ?: null);
        (new AuditLogService())->log(Auth::userId(), 'event', $id, 'update', null, ['name' => $name]);
        $this->flashSuccess('Událost byla uložena.');
        Response::redirect('/events/' . $id);
    }
}
