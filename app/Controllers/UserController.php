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
use App\Services\PermissionService;

final class UserController extends Controller
{
    public function profile(Request $request, array $params): void
    {
        $this->requireAuth();
        $userId = Auth::userId();

        $profile = (new UserRepository())->findProfileData($userId);
        $personId = $profile['person_id'] ?? null;
        $persons  = new PersonRepository();
        $perm     = new PermissionService();

        View::renderWithLayout('users/profile', [
            'title'        => 'Můj profil',
            'profile'      => $profile,
            'contacts'     => $personId !== null ? $persons->getContacts($personId) : [],
            'contactTypes' => $persons->listActiveContactTypes(),
            'roles'        => $perm->userRoles($userId),
            'activeRole'   => $perm->activeRole($userId),
            'flash'        => $this->getFlash(),
        ]);
    }

    public function addContact(Request $request, array $params): void
    {
        $this->requireAuth();
        $userId   = Auth::userId();
        $personId = (new UserRepository())->findProfileData($userId)['person_id'] ?? null;

        if ($personId === null) {
            $this->flashError('Váš účet není propojen s žádnou osobou.');
            Response::redirect('/profile');
        }

        $contactTypeId = (int) $request->post('contact_type_id', 0);
        $value         = trim((string) $request->post('value', ''));
        $note          = trim((string) $request->post('note', '')) ?: null;

        if ($contactTypeId <= 0 || $value === '') {
            $this->failForm('Vyberte typ kontaktu a vyplňte hodnotu.', '/profile', $contactTypeId <= 0 ? ['contact_type_id'] : ['value']);
        }

        $repo      = new PersonRepository();
        $contactId = $repo->addContact($personId, $contactTypeId, $value, $note);

        (new AuditLogService())->log($userId, 'contact', $contactId, 'create', null, ['person_id' => $personId, 'value' => $value]);

        $this->flashSuccess('Kontakt byl přidán.');
        Response::redirect('/profile');
    }

    public function deactivateContact(Request $request, array $params): void
    {
        $this->requireAuth();
        $userId    = Auth::userId();
        $contactId = (int) $params['contactId'];
        $repo      = new PersonRepository();

        if (!$this->ownsContact($repo, $userId, $contactId)) {
            Response::forbidden();
        }

        $repo->deactivateContact($contactId);
        (new AuditLogService())->log($userId, 'contact', $contactId, 'deactivate');

        $this->flashSuccess('Kontakt byl deaktivován.');
        Response::redirect('/profile');
    }

    public function activateContact(Request $request, array $params): void
    {
        $this->requireAuth();
        $userId    = Auth::userId();
        $contactId = (int) $params['contactId'];
        $repo      = new PersonRepository();

        if (!$this->ownsContact($repo, $userId, $contactId)) {
            Response::forbidden();
        }

        $repo->activateContact($contactId);
        (new AuditLogService())->log($userId, 'contact', $contactId, 'activate');

        $this->flashSuccess('Kontakt byl aktivován.');
        Response::redirect('/profile');
    }

    public function changeEmail(Request $request, array $params): void
    {
        $this->requireAuth();
        $userId = Auth::userId();

        $newEmail = trim((string) $request->post('email', ''));
        $password = (string) $request->post('current_password', '');

        try {
            Auth::changeEmail($userId, $newEmail, $password);
            (new AuditLogService())->log($userId, 'user', $userId, 'change_email');
            $this->flashSuccess('Email byl změněn.');
        } catch (\Throwable $e) {
            $this->flashError($e->getMessage());
        }

        Response::redirect('/profile');
    }

    public function changePassword(Request $request, array $params): void
    {
        $this->requireAuth();
        $userId = Auth::userId();

        $current = (string) $request->post('current_password', '');
        $newPass = (string) $request->post('new_password', '');
        $confirm = (string) $request->post('new_password_confirm', '');

        if ($newPass === '' || $newPass !== $confirm) {
            $this->flashError('Nová hesla se neshodují nebo jsou prázdná.');
            Response::redirect('/profile');
        }

        try {
            Auth::changePassword($userId, $current, $newPass);
            (new AuditLogService())->log($userId, 'user', $userId, 'change_password');
            $this->flashSuccess('Heslo bylo změněno.');
        } catch (\Throwable $e) {
            $this->flashError($e->getMessage());
        }

        Response::redirect('/profile');
    }

    private function ownsContact(PersonRepository $repo, int $userId, int $contactId): bool
    {
        $personId = (new UserRepository())->findProfileData($userId)['person_id'] ?? null;
        return $personId !== null && $repo->contactPersonId($contactId) === $personId;
    }
}
