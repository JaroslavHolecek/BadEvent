<?php

declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected function render(string $template, array $data = []): void
    {
        View::renderWithLayout($template, $data);
    }

    protected function renderPartial(string $template, array $data = []): string
    {
        return View::render($template, $data);
    }

    protected function redirect(string $url): never
    {
        Response::redirect($url);
    }

    protected function json(mixed $data, int $status = 200): never
    {
        Response::json($data, $status);
    }

    protected function requireAuth(): void
    {
        if (!Auth::check()) {
            Response::redirect('/login');
        }
    }

    /** Redirects to the homepage with a flash error when the user lacks the operation — e.g. after a role change leaves them without access to the page they were on. */
    protected function requirePermission(string $operation): void
    {
        $this->requireAuth();
        $userId = Auth::userId();

        $permService = new \App\Services\PermissionService();
        if ($userId === null || !$permService->can($userId, $operation)) {
            $this->flashError('Nemáte oprávnění k této stránce.');
            Response::redirect('/');
        }
    }

    /** Redirects to the homepage with a flash error unless the user's active role is 'administrator'. */
    protected function requireAdmin(): void
    {
        $this->requireAuth();
        $userId = Auth::userId();

        $permService = new \App\Services\PermissionService();
        if ($userId === null || !$permService->isAdmin($userId)) {
            $this->flashError('Tato stránka je dostupná pouze administrátorovi.');
            Response::redirect('/');
        }
    }

    /** Operation codes allowed under the current user's active role, for gating UI elements in views. */
    protected function allowedOps(): array
    {
        $userId = Auth::userId();
        if ($userId === null) {
            return [];
        }
        return (new \App\Services\PermissionService())->allowedOperations($userId);
    }

    protected function flashError(string $message): void
    {
        $_SESSION['flash_error'] = $message;
    }

    protected function flashSuccess(string $message): void
    {
        $_SESSION['flash_success'] = $message;
    }

    /**
     * Rejects a submitted form: flash error + the submitted values and the invalid places, so the
     * page redirected to can refill the form and highlight/scroll to what needs fixing (public/
     * assets/js/main.js → initFormFeedback). Nothing is stored for password-like fields.
     *
     * @param string[] $fields  field names to highlight; "name[2]" with no such literal field means
     *                          the 3rd "name[]" field of the form (rows of repeated inputs)
     * @param string[] $targets element ids to highlight when the problem isn't a single field (e.g. a page section)
     * @param ?array   $input   values to refill the form with; defaults to the whole POST body
     */
    protected function failForm(string $message, string $redirectTo, array $fields = [], array $targets = [], ?array $input = null): never
    {
        $this->flashError($message);
        $_SESSION['flash_form'] = [
            'action'  => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '',
            'fields'  => array_values($fields),
            'targets' => array_values($targets),
            'old'     => self::withoutPasswords($input ?? $_POST),
        ];
        Response::redirect($redirectTo);
    }

    private static function withoutPasswords(array $input): array
    {
        $kept = [];
        foreach ($input as $key => $value) {
            if (preg_match('/pass|heslo|token|csrf/i', (string) $key)) {
                continue;
            }
            $kept[$key] = is_array($value) ? self::withoutPasswords($value) : $value;
        }
        return $kept;
    }

    protected function getFlash(): array
    {
        $flash = [
            'error'   => $_SESSION['flash_error'] ?? null,
            'success' => $_SESSION['flash_success'] ?? null,
            'form'    => $_SESSION['flash_form'] ?? null,
        ];
        unset($_SESSION['flash_error'], $_SESSION['flash_success'], $_SESSION['flash_form']);
        return $flash;
    }
}
