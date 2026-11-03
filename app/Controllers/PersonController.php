<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\PersonRepository;
use App\Repositories\UserRepository;
use App\Services\AuditLogService;

final class PersonController extends Controller
{
    public function index(Request $request, array $params): void
    {
        $this->requirePermission('person.view');

        $repo = new PersonRepository();

        View::renderWithLayout('people/index', [
            'title'      => 'Osoby',
            'people'     => $repo->listAll(),
            'allowedOps' => $this->allowedOps(),
            'flash'      => $this->getFlash(),
        ]);
    }

    public function show(Request $request, array $params): void
    {
        $this->requirePermission('person.view');

        $id     = (int) $params['id'];
        $repo   = new PersonRepository();
        $person = $repo->findById($id);

        if ($person === null) {
            Response::notFound();
        }

        View::renderWithLayout('people/show', [
            'title'            => $person['full_name'],
            'person'           => $person,
            'contacts'         => $repo->getContacts($id),
            'contactTypes'     => $repo->listActiveContactTypes(),
            'linkedUser'       => (new UserRepository())->findByPersonId($id),
            'allowedOps'       => $this->allowedOps(),
            'flash'            => $this->getFlash(),
        ]);
    }

    /**
     * Creates a login account (app_users + bl_login) linked to this existing person, instead of
     * a new person the way self-registration/AdminController::createUser do — reuses the same
     * Auth::register() + UserRepository::createFromLogin() pair createFromLogin was built for.
     */
    public function createUser(Request $request, array $params): void
    {
        $this->requirePermission('users.manage');

        $id     = (int) $params['id'];
        $repo   = new PersonRepository();
        $person = $repo->findById($id);

        if ($person === null) {
            Response::notFound();
        }

        $users = new UserRepository();
        if ($users->findByPersonId($id) !== null) {
            $this->flashError('Tato osoba už má přiřazený uživatelský účet.');
            Response::redirect('/people/' . $id);
        }

        $email = trim((string) $request->post('email', ''));
        if ($email === '') {
            $this->failForm('E-mail je povinný.', '/people/' . $id, ['email']);
        }

        try {
            $loginId = Auth::register($email, null);
            $users->createFromLogin($loginId, $id);
        } catch (\Throwable $e) {
            $this->failForm('Nastala chyba: ' . $e->getMessage(), '/people/' . $id, ['email']);
        }

        (new AuditLogService())->log(Auth::userId(), 'user', $loginId, 'admin_create_user_from_person', null, ['person_id' => $id, 'email' => $email]);

        $this->flashSuccess('Uživatel byl vytvořen a přiřazen k této osobě. Přihlásit se může přes odkaz zaslaný na e-mail (volba „Odkaz na email“ na přihlašovací stránce).');
        Response::redirect('/people/' . $id);
    }

    public function createForm(Request $request, array $params): void
    {
        $this->requirePermission('person.manage');

        View::renderWithLayout('people/create', [
            'title'    => 'Nová osoba',
            'flash'    => $this->getFlash(),
            'formData' => [],
        ]);
    }

    public function createPost(Request $request, array $params): void
    {
        $this->requirePermission('person.manage');

        $fullName  = trim((string) $request->post('full_name', ''));
        $shortName = trim((string) $request->post('short_name', '')) ?: null;
        $note      = trim((string) $request->post('note', '')) ?: null;

        if ($fullName === '') {
            $this->failForm('Celé jméno je povinné.', '/people/create', ['full_name']);
        }

        $repo = new PersonRepository();
        $id   = $repo->create($fullName, $shortName, $note);

        (new AuditLogService())->log(Auth::userId(), 'person', $id, 'create', null, ['full_name' => $fullName]);

        $this->flashSuccess('Osoba byla vytvořena.');
        Response::redirect('/people/' . $id);
    }

    public function editForm(Request $request, array $params): void
    {
        $this->requirePermission('person.manage');

        $id     = (int) $params['id'];
        $person = (new PersonRepository())->findById($id);

        if ($person === null) {
            Response::notFound();
        }

        View::renderWithLayout('people/edit', [
            'title'  => 'Upravit: ' . $person['full_name'],
            'person' => $person,
            'flash'  => $this->getFlash(),
        ]);
    }

    public function editPost(Request $request, array $params): void
    {
        $this->requirePermission('person.manage');

        $id        = (int) $params['id'];
        $fullName  = trim((string) $request->post('full_name', ''));
        $shortName = trim((string) $request->post('short_name', '')) ?: null;
        $note      = trim((string) $request->post('note', '')) ?: null;

        if ($fullName === '') {
            $this->failForm('Celé jméno je povinné.', '/people/' . $id . '/edit', ['full_name']);
        }

        (new PersonRepository())->update($id, $fullName, $shortName, $note);
        (new AuditLogService())->log(Auth::userId(), 'person', $id, 'update', null, ['full_name' => $fullName]);

        $this->flashSuccess('Osoba byla uložena.');
        Response::redirect('/people/' . $id);
    }

    public function deactivate(Request $request, array $params): void
    {
        $this->requirePermission('person.manage');

        $id = (int) $params['id'];
        (new PersonRepository())->deactivate($id);
        (new AuditLogService())->log(Auth::userId(), 'person', $id, 'deactivate');

        $this->flashSuccess('Osoba byla deaktivována.');
        Response::redirect('/people/' . $id);
    }

    public function activate(Request $request, array $params): void
    {
        $this->requirePermission('person.manage');

        $id = (int) $params['id'];
        (new PersonRepository())->activate($id);
        (new AuditLogService())->log(Auth::userId(), 'person', $id, 'activate');

        $this->flashSuccess('Osoba byla aktivována.');
        Response::redirect('/people/' . $id);
    }

    public function delete(Request $request, array $params): void
    {
        $this->requirePermission('person.manage');

        $id = (int) $params['id'];

        $linkedUser = (new UserRepository())->findByPersonId($id);
        if ($linkedUser !== null) {
            $this->flashError(
                'Osobu nelze smazat, protože je přiřazena k uživatelskému účtu'
                . (!empty($linkedUser['email']) ? ' (' . $linkedUser['email'] . ')' : '')
                . '. Nejprve smažte uživatele.'
            );
            Response::redirect('/people/' . $id);
        }

        (new PersonRepository())->softDelete($id);
        (new AuditLogService())->log(Auth::userId(), 'person', $id, 'delete');

        $this->flashSuccess('Osoba byla smazána.');
        Response::redirect('/people');
    }

    public function addContact(Request $request, array $params): void
    {
        $this->requirePermission('person.manage');

        $id            = (int) $params['id'];
        $contactTypeId = (int) $request->post('contact_type_id', 0);
        $value         = trim((string) $request->post('value', ''));
        $note          = trim((string) $request->post('note', '')) ?: null;

        if ($contactTypeId <= 0 || $value === '') {
            $this->failForm('Vyberte typ kontaktu a vyplňte hodnotu.', '/people/' . $id, $contactTypeId <= 0 ? ['contact_type_id'] : ['value']);
        }

        $repo       = new PersonRepository();
        $contactId  = $repo->addContact($id, $contactTypeId, $value, $note);

        (new AuditLogService())->log(Auth::userId(), 'contact', $contactId, 'create', null, ['person_id' => $id, 'value' => $value]);

        $this->flashSuccess('Kontakt byl přidán.');
        Response::redirect('/people/' . $id);
    }

    public function deactivateContact(Request $request, array $params): void
    {
        $this->requirePermission('person.manage');

        $id        = (int) $params['id'];
        $contactId = (int) $params['contactId'];

        (new PersonRepository())->deactivateContact($contactId);
        (new AuditLogService())->log(Auth::userId(), 'contact', $contactId, 'deactivate', null, ['person_id' => $id]);

        $this->flashSuccess('Kontakt byl deaktivován.');
        Response::redirect('/people/' . $id);
    }

    public function activateContact(Request $request, array $params): void
    {
        $this->requirePermission('person.manage');

        $id        = (int) $params['id'];
        $contactId = (int) $params['contactId'];

        (new PersonRepository())->activateContact($contactId);
        (new AuditLogService())->log(Auth::userId(), 'contact', $contactId, 'activate', null, ['person_id' => $id]);

        $this->flashSuccess('Kontakt byl aktivován.');
        Response::redirect('/people/' . $id);
    }

    public function editContact(Request $request, array $params): void
    {
        $this->requirePermission('person.manage');

        $id            = (int) $params['id'];
        $contactId     = (int) $params['contactId'];
        $contactTypeId = (int) $request->post('contact_type_id', 0);
        $value         = trim((string) $request->post('value', ''));
        $note          = trim((string) $request->post('note', '')) ?: null;

        if ($contactTypeId <= 0 || $value === '') {
            $this->failForm('Vyberte typ kontaktu a vyplňte hodnotu.', '/people/' . $id, $contactTypeId <= 0 ? ['contact_type_id'] : ['value']);
        }

        $repo = new PersonRepository();
        if ($repo->contactPersonId($contactId) !== $id) {
            Response::notFound();
        }

        $repo->updateContact($contactId, $contactTypeId, $value, $note);
        (new AuditLogService())->log(Auth::userId(), 'contact', $contactId, 'update', null, ['person_id' => $id, 'value' => $value]);

        $this->flashSuccess('Kontakt byl upraven.');
        Response::redirect('/people/' . $id);
    }

    public function deleteContact(Request $request, array $params): void
    {
        $this->requirePermission('person.manage');

        $id        = (int) $params['id'];
        $contactId = (int) $params['contactId'];

        $repo = new PersonRepository();
        if ($repo->contactPersonId($contactId) !== $id) {
            Response::notFound();
        }

        $repo->deleteContact($contactId);
        (new AuditLogService())->log(Auth::userId(), 'contact', $contactId, 'delete', null, ['person_id' => $id]);

        $this->flashSuccess('Kontakt byl smazán.');
        Response::redirect('/people/' . $id);
    }
}
