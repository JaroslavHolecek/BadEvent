<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Services\AuditLogService;
use App\Services\PermissionService;

final class AuthController extends Controller
{
    public function loginPage(Request $request, array $params): void
    {
        if (Auth::check()) {
            Response::redirect('/');
        }

        $token = $request->get('token');
        if ($token !== null) {
            $ok = Auth::loginWithToken((string) $token);
            if ($ok) {
                $userId = Auth::userId();
                (new AuditLogService())->log($userId, 'user', (int) $userId, 'login_token');
                Response::redirect('/');
            }
            $flash = ['error' => 'Přihlašovací odkaz je neplatný nebo vypršel.', 'success' => null];
        } else {
            $flash = $this->getFlash();
        }

        View::renderWithLayout('auth/login', [
            'title' => 'Přihlášení',
            'flash' => $flash,
        ]);
    }

    public function loginPost(Request $request, array $params): void
    {
        $email    = trim((string) $request->post('email', ''));
        $password = (string) $request->post('password', '');
        $method   = $request->post('method', 'password');

        if ($method === 'link') {
            try {
                Auth::requestLoginToken($email);
                $this->flashSuccess('Přihlašovací odkaz byl odeslán na váš email, pokud tento email existuje v databázi.');
            } catch (\Throwable $e) {
                $this->flashError('Nastala chyba: ' . $e->getMessage());
            }
            Response::redirect('/login');
        }

        try {
            Auth::loginWithPassword($email, $password);
            $userId = Auth::userId();
            (new AuditLogService())->log($userId, 'user', (int) $userId, 'login');
            Response::redirect('/');
        } catch (\Throwable $e) {
            (new AuditLogService())->log(null, 'user', 0, 'login_failed', null, ['email' => $email]);
            $this->flashError($e->getMessage());
            Response::redirect('/login');
        }
    }

    public function logout(Request $request, array $params): void
    {
        $userId = Auth::userId();
        try {
            (new AuditLogService())->log($userId, 'user', (int) $userId, 'logout');
        } catch (\Throwable $e) {
            // Logging out must always succeed even if the audit trail write fails
            // (e.g. the session's user id has no matching app_users row).
        }
        Auth::logout();
        Response::redirect('/login');
    }

    /** Switches the session's active role among the user's assigned roles. */
    public function switchRole(Request $request, array $params): void
    {
        $this->requireAuth();
        $userId = Auth::userId();
        $roleId = (int) $params['roleId'];

        if (!(new PermissionService())->switchRole($userId, $roleId)) {
            $this->flashError('Tato role vám není přiřazena.');
        } else {
            (new AuditLogService())->log($userId, 'user', $userId, 'switch_role', null, ['role_id' => $roleId]);
        }

        $returnTo = (string) $request->post('return_to', '/');
        $this->redirect(preg_match('#^/(?!/)#', $returnTo) === 1 ? $returnTo : '/');
    }

    public function registerPage(Request $request, array $params): void
    {
        if (Auth::check()) {
            Response::redirect('/');
        }

        View::renderWithLayout('auth/register', [
            'title' => 'Registrace',
            'flash' => $this->getFlash(),
        ]);
    }

    public function registerPost(Request $request, array $params): void
    {
        $fullName        = trim((string) $request->post('full_name', ''));
        $email           = trim((string) $request->post('email', ''));
        $password        = (string) $request->post('password', '');
        $passwordConfirm = (string) $request->post('password_confirm', '');

        if ($fullName === '') {
            $this->flashError('Celé jméno je povinné.');
            Response::redirect('/register');
        }

        if ($password !== $passwordConfirm) {
            $this->flashError('Hesla se neshodují.');
            Response::redirect('/register');
        }

        try {
            $loginId = Auth::register($email, $password !== '' ? $password : null);
            (new UserRepository())->registerNewUser($loginId, $fullName);
            (new AuditLogService())->log($loginId, 'user', $loginId, 'register', null, ['email' => $email, 'full_name' => $fullName]);
            $this->flashSuccess('Účet byl vytvořen. Nyní se přihlaste.');
            Response::redirect('/login');
        } catch (\Throwable $e) {
            $this->flashError($e->getMessage());
            Response::redirect('/register');
        }
    }
}
