<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\DictionaryRepository;
use App\Repositories\PlaceRepository;
use App\Repositories\UserRepository;
use App\Services\AuditLogService;

final class DictionaryController extends Controller
{
    public function index(Request $request, array $params): void
    {
        $this->requirePermission('dictionaries.manage');

        $repo         = new DictionaryRepository();
        $selectedType = (string) $request->get('type', 'contact_types');
        $knownTypes   = array_merge(array_keys(DictionaryRepository::types()), ['currencies', 'places']);
        if (!in_array($selectedType, $knownTypes, true)) {
            $selectedType = 'contact_types';
        }

        $selectedIncomeTypeId = (int) $request->get('income_type_id', 0);

        View::renderWithLayout('admin/dictionaries', [
            'title'                => 'Číselníky a místnosti',
            'types'                => DictionaryRepository::types(),
            'selectedType'         => $selectedType,
            'items'                => match ($selectedType) {
                'currencies' => $repo->listCurrencies(),
                'places'     => [],
                default      => $repo->listAll($selectedType),
            },
            'selectedIncomeTypeId' => $selectedIncomeTypeId,
            'incomeSubtypes'       => $selectedIncomeTypeId > 0 ? $repo->listIncomeSubtypes($selectedIncomeTypeId) : [],
            'places'               => (new PlaceRepository())->listAll(),
            'placeManagerCandidates' => (new UserRepository())->listByRoleCode('place_manager'),
            'flash'                => $this->getFlash(),
        ]);
    }

    public function create(Request $request, array $params): void
    {
        $this->requirePermission('dictionaries.manage');

        $type = (string) $params['type'];
        if (!array_key_exists($type, DictionaryRepository::types())) {
            Response::notFound();
        }

        $name = trim((string) $request->post('name', ''));
        if ($name === '') {
            $this->failForm('Název je povinný.', '/admin/dictionaries?type=' . $type, ['name']);
        }

        $abbreviation = trim((string) $request->post('abbreviation', '')) ?: null;

        $repo = new DictionaryRepository();
        $id   = $repo->create($type, $name, $abbreviation);

        (new AuditLogService())->log(Auth::userId(), $type, $id, 'create', null, ['name' => $name, 'abbreviation' => $abbreviation]);

        $this->flashSuccess('Položka byla vytvořena.');
        $this->redirect('/admin/dictionaries?type=' . $type);
    }

    public function update(Request $request, array $params): void
    {
        $this->requirePermission('dictionaries.manage');

        $type = (string) $params['type'];
        $id   = (int) $params['id'];
        if (!array_key_exists($type, DictionaryRepository::types())) {
            Response::notFound();
        }

        $name = trim((string) $request->post('name', ''));
        if ($name === '') {
            $this->failForm('Název je povinný.', '/admin/dictionaries?type=' . $type, ['name']);
        }

        $abbreviation = trim((string) $request->post('abbreviation', '')) ?: null;

        (new DictionaryRepository())->update($type, $id, $name, $abbreviation);
        (new AuditLogService())->log(Auth::userId(), $type, $id, 'update', null, ['name' => $name, 'abbreviation' => $abbreviation]);

        $this->flashSuccess('Položka byla upravena.');
        $this->redirect('/admin/dictionaries?type=' . $type);
    }

    public function deactivate(Request $request, array $params): void
    {
        $this->requirePermission('dictionaries.manage');

        $type = (string) $params['type'];
        $id   = (int) $params['id'];
        if (!array_key_exists($type, DictionaryRepository::types())) {
            Response::notFound();
        }

        (new DictionaryRepository())->deactivate($type, $id);
        (new AuditLogService())->log(Auth::userId(), $type, $id, 'deactivate');

        $this->flashSuccess('Položka byla deaktivována.');
        $this->redirect('/admin/dictionaries?type=' . $type);
    }

    public function activate(Request $request, array $params): void
    {
        $this->requirePermission('dictionaries.manage');

        $type = (string) $params['type'];
        $id   = (int) $params['id'];
        if (!array_key_exists($type, DictionaryRepository::types())) {
            Response::notFound();
        }

        (new DictionaryRepository())->activate($type, $id);
        (new AuditLogService())->log(Auth::userId(), $type, $id, 'activate');

        $this->flashSuccess('Položka byla aktivována.');
        $this->redirect('/admin/dictionaries?type=' . $type);
    }

    public function createCurrency(Request $request, array $params): void
    {
        $this->requirePermission('dictionaries.manage');

        $code = strtoupper(trim((string) $request->post('code', '')));
        $name = trim((string) $request->post('name', ''));
        if ($code === '' || $name === '') {
            $this->failForm('Kód i název měny jsou povinné.', '/admin/dictionaries?type=currencies', $code === '' ? ['code'] : ['name']);
        }

        $symbol         = trim((string) $request->post('symbol', '')) ?: null;
        $decimalPlaces  = $request->post('decimal_places', '') !== '' ? (int) $request->post('decimal_places') : 2;

        $repo = new DictionaryRepository();
        $id   = $repo->createCurrency($code, $name, $symbol, $decimalPlaces);

        (new AuditLogService())->log(Auth::userId(), 'currencies', $id, 'create', null, ['code' => $code, 'name' => $name]);

        $this->flashSuccess('Měna byla vytvořena.');
        $this->redirect('/admin/dictionaries?type=currencies');
    }

    public function deactivateCurrency(Request $request, array $params): void
    {
        $this->requirePermission('dictionaries.manage');

        $id = (int) $params['id'];
        (new DictionaryRepository())->deactivateCurrency($id);
        (new AuditLogService())->log(Auth::userId(), 'currencies', $id, 'deactivate');

        $this->flashSuccess('Měna byla deaktivována.');
        $this->redirect('/admin/dictionaries?type=currencies');
    }

    public function activateCurrency(Request $request, array $params): void
    {
        $this->requirePermission('dictionaries.manage');

        $id = (int) $params['id'];
        (new DictionaryRepository())->activateCurrency($id);
        (new AuditLogService())->log(Auth::userId(), 'currencies', $id, 'activate');

        $this->flashSuccess('Měna byla aktivována.');
        $this->redirect('/admin/dictionaries?type=currencies');
    }

    public function createIncomeSubtype(Request $request, array $params): void
    {
        $this->requirePermission('dictionaries.manage');

        $incomeTypeId = (int) $params['incomeTypeId'];
        $name         = trim((string) $request->post('name', ''));
        if ($name === '') {
            $this->failForm('Název je povinný.', '/admin/dictionaries?type=income_types&income_type_id=' . $incomeTypeId, ['name']);
        }

        $repo = new DictionaryRepository();
        $id   = $repo->createIncomeSubtype($incomeTypeId, $name);

        (new AuditLogService())->log(Auth::userId(), 'income_subtypes', $id, 'create', null, ['income_type_id' => $incomeTypeId, 'name' => $name]);

        $this->flashSuccess('Podtyp příjmu byl vytvořen.');
        $this->redirect('/admin/dictionaries?type=income_types&income_type_id=' . $incomeTypeId);
    }

    public function updateIncomeSubtype(Request $request, array $params): void
    {
        $this->requirePermission('dictionaries.manage');

        $id           = (int) $params['id'];
        $incomeTypeId = (int) $request->post('income_type_id', 0);
        $name         = trim((string) $request->post('name', ''));

        if ($name === '') {
            $this->failForm('Název je povinný.', '/admin/dictionaries?type=income_types&income_type_id=' . $incomeTypeId, ['name']);
        }

        (new DictionaryRepository())->updateIncomeSubtype($id, $name);
        (new AuditLogService())->log(Auth::userId(), 'income_subtypes', $id, 'update', null, ['name' => $name]);

        $this->flashSuccess('Podtyp příjmu byl upraven.');
        $this->redirect('/admin/dictionaries?type=income_types&income_type_id=' . $incomeTypeId);
    }

    public function deactivateIncomeSubtype(Request $request, array $params): void
    {
        $this->requirePermission('dictionaries.manage');

        $id           = (int) $params['id'];
        $incomeTypeId = (int) $request->post('income_type_id', 0);

        (new DictionaryRepository())->deactivateIncomeSubtype($id);
        (new AuditLogService())->log(Auth::userId(), 'income_subtypes', $id, 'deactivate');

        $this->flashSuccess('Podtyp příjmu byl deaktivován.');
        $this->redirect('/admin/dictionaries?type=income_types&income_type_id=' . $incomeTypeId);
    }

    public function activateIncomeSubtype(Request $request, array $params): void
    {
        $this->requirePermission('dictionaries.manage');

        $id           = (int) $params['id'];
        $incomeTypeId = (int) $request->post('income_type_id', 0);

        (new DictionaryRepository())->activateIncomeSubtype($id);
        (new AuditLogService())->log(Auth::userId(), 'income_subtypes', $id, 'activate');

        $this->flashSuccess('Podtyp příjmu byl aktivován.');
        $this->redirect('/admin/dictionaries?type=income_types&income_type_id=' . $incomeTypeId);
    }
}
