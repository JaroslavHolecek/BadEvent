<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\PersonRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\AuditLogService;

final class AdminController extends Controller
{
    public function roles(Request $request, array $params): void
    {
        $this->requirePermission('users.manage');

        $repo  = new RoleRepository();
        $roles = $repo->listAll();

        $selectedRoleId = (int) $request->get('role_id', 0);
        $selectedRole   = $selectedRoleId > 0 ? $repo->findById($selectedRoleId) : null;
        $operations     = $selectedRole !== null ? $repo->listOperationsForRole($selectedRoleId) : [];

        View::renderWithLayout('admin/roles', [
            'title'        => 'Uživatelé, role a oprávnění',
            'roles'        => $roles,
            'selectedRole' => $selectedRole,
            'operations'   => $operations,
            'users'        => (new UserRepository())->listAll(),
            'flash'        => $this->getFlash(),
        ]);
    }

    /** Only an administrator may create new users; there's no public self-registration anymore. */
    public function createUser(Request $request, array $params): void
    {
        $this->requirePermission('users.manage');

        $email    = trim((string) $request->post('email', ''));
        $fullName = trim((string) $request->post('full_name', ''));

        if ($email === '' || $fullName === '') {
            $this->failForm('E-mail i celé jméno jsou povinné.', '/admin/roles', $email === '' ? ['email'] : ['full_name']);
        }

        try {
            $loginId = Auth::register($email, null);
            (new UserRepository())->registerNewUser($loginId, $fullName);
        } catch (\Throwable $e) {
            $this->failForm('Nastala chyba: ' . $e->getMessage(), '/admin/roles', ['email']);
        }

        (new AuditLogService())->log(Auth::userId(), 'user', $loginId, 'admin_create_user', null, ['email' => $email, 'full_name' => $fullName]);

        $this->flashSuccess('Uživatel byl vytvořen. Přihlásit se může přes odkaz zaslaný na e-mail (volba „Odkaz na email“ na přihlašovací stránce).');
        $this->redirect('/admin/roles');
    }

    public function assignUserRole(Request $request, array $params): void
    {
        $this->requirePermission('users.manage');

        $userId = (int) $params['userId'];
        $roleId = (int) $request->post('role_id', 0);

        if ($roleId <= 0) {
            $this->flashError('Vyberte roli.');
            $this->redirect('/admin/roles');
        }

        (new UserRepository())->assignRole($userId, $roleId);
        (new AuditLogService())->log(Auth::userId(), 'user', $userId, 'assign_role', null, ['role_id' => $roleId]);

        $this->flashSuccess('Role byla uživateli přidána.');
        $this->redirect('/admin/roles');
    }

    public function removeUserRole(Request $request, array $params): void
    {
        $this->requirePermission('users.manage');

        $userId = (int) $params['userId'];
        $roleId = (int) $params['roleId'];
        $users  = new UserRepository();

        $role = (new RoleRepository())->findById($roleId);
        if ($role !== null && $role['code'] === 'administrator' && $users->isLastAdmin($userId)) {
            $this->flashError('Nelze odebrat roli administrátora poslednímu administrátorovi.');
            $this->redirect('/admin/roles');
        }

        $users->removeRole($userId, $roleId);
        (new AuditLogService())->log(Auth::userId(), 'user', $userId, 'remove_role', ['role_id' => $roleId], null);

        $this->flashSuccess('Role byla uživateli odebrána.');
        $this->redirect('/admin/roles');
    }

    public function deleteUser(Request $request, array $params): void
    {
        $this->requirePermission('users.manage');

        $userId = (int) $params['userId'];
        $users  = new UserRepository();

        if ($users->hasRoleCode($userId, 'administrator') && $users->isLastAdmin($userId)) {
            $this->flashError('Nelze smazat posledního administrátora.');
            $this->redirect('/admin/roles');
        }

        // Fetched before softDelete() detaches it (soft_delete_app_user sets app_users.person_id
        // to NULL), since deleting the person afterward — if requested — needs this id.
        $user     = $users->findById($userId);
        $personId = $user['person_id'] ?? null;

        $users->softDelete($userId);
        (new AuditLogService())->log(Auth::userId(), 'user', $userId, 'delete');

        // Deleting the user never implies deleting the person by default — the checkbox in
        // admin/roles.php must be explicitly checked, per spec ("defaultně bude osoba ponechána").
        if ($personId !== null && $request->post('delete_person') === '1') {
            (new PersonRepository())->softDelete((int) $personId);
            (new AuditLogService())->log(Auth::userId(), 'person', (int) $personId, 'delete');
            $this->flashSuccess('Uživatel i přiřazená osoba byli smazáni.');
        } else {
            $this->flashSuccess('Uživatel byl smazán.');
        }

        $this->redirect('/admin/roles');
    }

    public function deactivateUser(Request $request, array $params): void
    {
        $this->requirePermission('users.manage');

        $userId = (int) $params['userId'];
        $users  = new UserRepository();

        if ($users->hasRoleCode($userId, 'administrator') && $users->isLastAdmin($userId)) {
            $this->flashError('Nelze deaktivovat posledního administrátora.');
            $this->redirect('/admin/roles');
        }

        $users->deactivate($userId);
        (new AuditLogService())->log(Auth::userId(), 'user', $userId, 'deactivate');

        $this->flashSuccess('Uživatel byl deaktivován.');
        $this->redirect('/admin/roles');
    }

    public function activateUser(Request $request, array $params): void
    {
        $this->requirePermission('users.manage');

        $userId = (int) $params['userId'];
        (new UserRepository())->activate($userId);
        (new AuditLogService())->log(Auth::userId(), 'user', $userId, 'activate');

        $this->flashSuccess('Uživatel byl aktivován.');
        $this->redirect('/admin/roles');
    }

    public function editUserForm(Request $request, array $params): void
    {
        $this->requirePermission('users.manage');

        $userId = (int) $params['userId'];
        $user   = (new UserRepository())->findById($userId);

        if ($user === null) {
            Response::notFound();
        }

        View::renderWithLayout('admin/user-edit', [
            'title' => 'Upravit uživatele: ' . ($user['full_name'] ?? ('#' . $user['id'])),
            'user'  => $user,
            'flash' => $this->getFlash(),
        ]);
    }

    public function editUserPost(Request $request, array $params): void
    {
        $this->requirePermission('users.manage');

        $userId = (int) $params['userId'];
        $note   = trim((string) $request->post('note', '')) ?: null;

        (new UserRepository())->updateNote($userId, $note);
        (new AuditLogService())->log(Auth::userId(), 'user', $userId, 'update', null, ['note' => $note]);

        $this->flashSuccess('Uživatel byl uložen.');
        $this->redirect('/admin/roles');
    }

    public function changeUserEmail(Request $request, array $params): void
    {
        $this->requirePermission('users.manage');

        $userId   = (int) $params['userId'];
        $newEmail = trim((string) $request->post('email', ''));

        if ($newEmail === '') {
            $this->failForm('E-mail je povinný.', '/admin/users/' . $userId . '/edit', ['email']);
        }

        try {
            Auth::adminChangeEmail($userId, $newEmail);
        } catch (\Bl_Exception $e) {
            $this->failForm($e->getMessage(), '/admin/users/' . $userId . '/edit', ['email']);
        }

        (new AuditLogService())->log(Auth::userId(), 'user', $userId, 'admin_change_email', null, ['email' => $newEmail]);

        $this->flashSuccess('Přihlašovací e-mail byl změněn.');
        $this->redirect('/admin/users/' . $userId . '/edit');
    }

    public function createRole(Request $request, array $params): void
    {
        $this->requirePermission('users.manage');

        $name = trim((string) $request->post('name', ''));
        $code = trim((string) $request->post('code', ''));

        if ($name === '' || $code === '') {
            $this->failForm('Název i kód role jsou povinné.', '/admin/roles', $name === '' ? ['name'] : ['code']);
        }

        if (!preg_match('/^[a-z0-9_]+$/', $code)) {
            $this->failForm('Kód role může obsahovat jen malá písmena, čísla a podtržítko.', '/admin/roles', ['code']);
        }

        $repo = new RoleRepository();
        if ($repo->codeExists($code)) {
            $this->failForm('Role s tímto kódem už existuje.', '/admin/roles', ['code']);
        }

        $roleId = $repo->create($name, $code);
        (new AuditLogService())->log(Auth::userId(), 'role', $roleId, 'create', null, ['name' => $name, 'code' => $code]);
        $this->flashSuccess('Role byla vytvořena.');
        $this->redirect('/admin/roles?role_id=' . $roleId);
    }

    public function grantOperation(Request $request, array $params): void
    {
        $this->requirePermission('users.manage');

        $roleId      = (int) $params['roleId'];
        $operationId = (int) $params['operationId'];

        (new RoleRepository())->grantOperation($roleId, $operationId);
        (new AuditLogService())->log(Auth::userId(), 'role', $roleId, 'grant_operation', null, ['operation_id' => $operationId]);

        $this->flashSuccess('Oprávnění bylo přidáno.');
        $this->redirect('/admin/roles?role_id=' . $roleId);
    }

    public function revokeOperation(Request $request, array $params): void
    {
        $this->requirePermission('users.manage');

        $roleId      = (int) $params['roleId'];
        $operationId = (int) $params['operationId'];
        $repo        = new RoleRepository();

        $role = $repo->findById($roleId);
        if ($role !== null && $role['code'] === 'administrator') {
            $this->flashError('Administrátor má vždy všechna oprávnění a nelze mu je odebrat.');
            $this->redirect('/admin/roles?role_id=' . $roleId);
        }

        $code = $repo->operationCode($operationId);
        if ($code === 'users.manage' && $repo->isOnlyRoleGrantingOperation($roleId, $code)) {
            $this->flashError('Nelze odebrat poslední přístup ke správě uživatelů a rolí.');
            $this->redirect('/admin/roles?role_id=' . $roleId);
        }

        $repo->revokeOperation($roleId, $operationId);
        (new AuditLogService())->log(Auth::userId(), 'role', $roleId, 'revoke_operation', ['operation_id' => $operationId], null);
        $this->flashSuccess('Oprávnění bylo odebráno.');
        $this->redirect('/admin/roles?role_id=' . $roleId);
    }
}
