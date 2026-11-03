<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\PlaceRepository;
use App\Repositories\UserRepository;
use App\Services\AuditLogService;

final class PlaceController extends Controller
{
    public function create(Request $request, array $params): void
    {
        $this->requirePermission('dictionaries.manage');

        $data = $request->post();
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            $this->failForm('Název místnosti je povinný.', '/admin/dictionaries?type=places', ['name']);
        }

        $repo = new PlaceRepository();
        $id   = $repo->create(
            $name,
            trim((string) ($data['abbreviation'] ?? '')) ?: null,
            isset($data['capacity']) && $data['capacity'] !== '' ? (int) $data['capacity'] : null,
            trim((string) ($data['note'] ?? '')) ?: null
        );

        $managerIds = array_map('intval', (array) ($data['manager_id'] ?? []));
        $repo->syncManagers($id, $managerIds);

        (new AuditLogService())->log(Auth::userId(), 'place', $id, 'create', null, ['name' => $name, 'manager_ids' => $managerIds]);

        $this->flashSuccess('Místnost byla vytvořena.');
        $this->redirect('/admin/dictionaries?type=places');
    }

    public function editForm(Request $request, array $params): void
    {
        $this->requirePermission('dictionaries.manage');

        $id    = (int) $params['id'];
        $repo  = new PlaceRepository();
        $place = $repo->findById($id);

        if ($place === null) {
            Response::notFound();
        }

        $managers = $repo->getManagers($id);

        View::renderWithLayout('places/edit', [
            'title'              => 'Upravit místnost: ' . $place['name'],
            'place'              => $place,
            'assignedManagerIds' => array_map('intval', array_column($managers, 'user_id')),
            'managerCandidates'  => (new UserRepository())->listByRoleCode('place_manager'),
            'flash'              => $this->getFlash(),
        ]);
    }

    public function editPost(Request $request, array $params): void
    {
        $this->requirePermission('dictionaries.manage');

        $id   = (int) $params['id'];
        $data = $request->post();
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            $this->failForm('Název místnosti je povinný.', '/places/' . $id . '/edit', ['name']);
        }

        $repo = new PlaceRepository();
        $repo->update(
            $id,
            $name,
            trim((string) ($data['abbreviation'] ?? '')) ?: null,
            isset($data['capacity']) && $data['capacity'] !== '' ? (int) $data['capacity'] : null,
            trim((string) ($data['note'] ?? '')) ?: null
        );

        $managerIds = array_map('intval', (array) ($data['manager_id'] ?? []));
        $repo->syncManagers($id, $managerIds);

        (new AuditLogService())->log(Auth::userId(), 'place', $id, 'update', null, ['name' => $name, 'manager_ids' => $managerIds]);

        $this->flashSuccess('Místnost byla uložena.');
        $this->redirect('/admin/dictionaries?type=places');
    }

    public function deactivate(Request $request, array $params): void
    {
        $this->requirePermission('dictionaries.manage');

        $id = (int) $params['id'];
        (new PlaceRepository())->deactivate($id);
        (new AuditLogService())->log(Auth::userId(), 'place', $id, 'deactivate');

        $this->flashSuccess('Místnost byla deaktivována.');
        $this->redirect('/admin/dictionaries?type=places');
    }

    public function activate(Request $request, array $params): void
    {
        $this->requirePermission('dictionaries.manage');

        $id = (int) $params['id'];
        (new PlaceRepository())->activate($id);
        (new AuditLogService())->log(Auth::userId(), 'place', $id, 'activate');

        $this->flashSuccess('Místnost byla aktivována.');
        $this->redirect('/admin/dictionaries?type=places');
    }
}
