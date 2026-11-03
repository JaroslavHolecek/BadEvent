<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Thin wrapper around the BadLogin module.
 * Loads the module once; all session state lives in $_SESSION.
 */
final class Auth
{
    private static bool  $loaded = false;
    private static array $blConfig = [];

    private static function bootstrap(): void
    {
        if (self::$loaded) {
            return;
        }

        // BadLogin needs its own config and sources its own files
        $blDir = __DIR__ . '/../../vendor/BadLogin';

        require_once $blDir . '/src/bl_exception.php';
        require_once $blDir . '/src/bl_config.php';
        require_once $blDir . '/src/bl_db.php';
        require_once $blDir . '/src/bl_mail.php';
        require_once $blDir . '/src/bl_linktoken.php';
        require_once $blDir . '/src/bl_session.php';
        require_once $blDir . '/src/bl_auth.php';

        self::$blConfig = Config::load('bl_config');
        self::$loaded   = true;
    }

    private static function cfg(): array
    {
        self::bootstrap();
        return self::$blConfig;
    }

    /**
     * True when the session holds a user id whose app_users row still has a linked person.
     *
     * bl_login.id and app_users.id are the same value — that id is what identifies "who is
     * logged in". A NULL app_users.person_id means the account was soft-deleted
     * (soft_delete_app_user clears person_id and anonymizes the bl_login row, but never removes
     * either row outright). BadLogin itself already refuses *new* logins for such an account
     * (soft_delete_bl_login wipes its password hash and link token), but a session created
     * before the deletion would otherwise stay "logged in" forever — so this re-checks
     * person_id on every call and, if it's gone, kills the stale session.
     */
    public static function check(): bool
    {
        self::bootstrap();
        if (!bl_auth_is_logged_in(self::cfg())) {
            return false;
        }

        $userId = bl_session_get_user_id(self::cfg());
        if ($userId === null || !self::hasLinkedPerson($userId)) {
            self::logout();
            return false;
        }

        return true;
    }

    private static function hasLinkedPerson(int $userId): bool
    {
        $stmt = Database::connection()->prepare('SELECT person_id FROM app_users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $personId = $stmt->fetchColumn();
        return $personId !== false && $personId !== null;
    }

    public static function userId(): ?int
    {
        self::bootstrap();
        return bl_session_get_user_id(self::cfg());
    }

    /**
     * @throws \Bl_Exception on bad credentials
     */
    public static function loginWithPassword(string $email, string $password): void
    {
        self::bootstrap();
        bl_auth_login_with_password($email, $password, Database::connection(), self::cfg());
    }

    public static function loginWithToken(string $token): bool
    {
        self::bootstrap();
        $user = bl_auth_login_with_linktoken($token, Database::connection(), self::cfg());
        return $user !== null;
    }

    public static function requestLoginToken(string $email): bool
    {
        self::bootstrap();
        return bl_auth_request_linktoken_login($email, Database::connection(), self::cfg());
    }

    public static function logout(): void
    {
        self::bootstrap();
        bl_auth_logout();
    }

    public static function register(string $email, ?string $password = null): int
    {
        self::bootstrap();
        return bl_auth_register($email, $password, Database::connection(), self::cfg());
    }

    /**
     * Changes the account's email address. Requires the current password when one is set
     * (link-token-only accounts, which have no password, skip that check).
     *
     * @throws \Bl_Exception on invalid password, invalid email, or a duplicate email
     */
    public static function changeEmail(int $userId, string $newEmail, string $currentPassword): void
    {
        self::bootstrap();
        $pdo = Database::connection();
        $cfg = self::cfg();

        $user = bl_db_find_user_by_id($userId, $pdo, $cfg);
        if ($user === null) {
            throw new \Bl_Exception(\Bl_Exception::USER_ERROR, 'Uživatel nenalezen.');
        }
        self::assertCurrentPassword($user, $currentPassword);

        self::applyEmailChange($userId, $newEmail);
    }

    /**
     * Administrative override: changes another user's login email without knowing (or checking)
     * their current password. Only ever called from an admin-gated controller action — the
     * caller is responsible for the users.manage permission check.
     *
     * @throws \Bl_Exception on invalid email, duplicate email, or unknown user
     */
    public static function adminChangeEmail(int $userId, string $newEmail): void
    {
        self::bootstrap();
        $pdo = Database::connection();
        $cfg = self::cfg();

        if (bl_db_find_user_by_id($userId, $pdo, $cfg) === null) {
            throw new \Bl_Exception(\Bl_Exception::USER_ERROR, 'Uživatel nenalezen.');
        }

        self::applyEmailChange($userId, $newEmail);
    }

    /** Shared by changeEmail() and adminChangeEmail(): validates and persists the new email, once the caller has decided the request is authorized. */
    private static function applyEmailChange(int $userId, string $newEmail): void
    {
        $pdo = Database::connection();
        $cfg = self::cfg();

        $newEmail = bl_auth_refine_email($newEmail);
        if (!bl_auth_is_valid_email($newEmail)) {
            throw new \Bl_Exception(\Bl_Exception::USER_ERROR, 'Neplatný formát emailu.');
        }

        $existing = bl_db_find_user_by_email($newEmail, $pdo, $cfg);
        if ($existing !== null && (int) $existing['id'] !== $userId) {
            throw new \Bl_Exception(\Bl_Exception::USER_ERROR, 'Tento email již používá jiný účet.');
        }

        $table = bl_config_get('_table_login', $cfg);
        $stmt  = $pdo->prepare("UPDATE {$table} SET email = :email, is_email_verified = FALSE WHERE id = :id");
        $stmt->execute([':email' => $newEmail, ':id' => $userId]);
    }

    /**
     * Changes the account's password. Requires the current password when one is set
     * (link-token-only accounts, which have no password, skip that check).
     *
     * @throws \Bl_Exception on invalid current password
     */
    public static function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        self::bootstrap();
        $pdo = Database::connection();
        $cfg = self::cfg();

        $user = bl_db_find_user_by_id($userId, $pdo, $cfg);
        if ($user === null) {
            throw new \Bl_Exception(\Bl_Exception::USER_ERROR, 'Uživatel nenalezen.');
        }
        self::assertCurrentPassword($user, $currentPassword);

        bl_db_set_password_hash($userId, bl_auth_hash_password($newPassword), $pdo, $cfg);
    }

    /** @throws \Bl_Exception when a password is set on the account and $currentPassword doesn't match it */
    private static function assertCurrentPassword(array $user, string $currentPassword): void
    {
        if ($user['password_hash'] === null || $user['password_hash'] === '') {
            return;
        }
        if ($currentPassword === '' || !password_verify($currentPassword, $user['password_hash'])) {
            throw new \Bl_Exception(\Bl_Exception::USER_ERROR, 'Současné heslo není správné.');
        }
    }
}
