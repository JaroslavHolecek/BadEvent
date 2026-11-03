<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\PermissionService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;

/** Role → operation matrix from database/seed.sql and the active-role mechanics. */
final class PermissionServiceTest extends DatabaseTestCase
{
    /** Expected operations per seeded role — any change to seed.sql permissions should be a conscious change here too. */
    public static function roleOperations(): array
    {
        $all = [
            'action.create', 'action.mark_not_agreed', 'action.confirm', 'action.cancel', 'action.complete',
            'action.close', 'action.delete', 'action.update_basic', 'action.own_action.update_basic',
            'action.update_economic', 'action.view_basic', 'action.view_economic', 'archive.view',
            'users.manage', 'dictionaries.manage', 'person.manage', 'person.view', 'building_code_duty.manage',
        ];

        return [
            'administrator' => ['administrator', $all],
            'organizer'     => ['organizer', [
                'action.create', 'action.mark_not_agreed', 'action.confirm', 'action.complete',
                'action.own_action.update_basic', 'action.view_basic', 'action.view_economic',
                'archive.view', 'person.manage', 'person.view',
            ]],
            'substitute'    => ['substitute', ['action.view_basic']],
            'stuff'         => ['stuff', ['action.view_basic']],
            'viewer'        => ['viewer', ['action.view_basic', 'archive.view']],
            'place_manager' => ['place_manager', ['action.view_basic']],
        ];
    }

    /** Každá role ze seed.sql má přesně svou sadu oprávnění (nic navíc, nic chybějícího). */
    #[DataProvider('roleOperations')]
    public function testRoleHasExactlyItsOperations(string $role, array $expected): void
    {
        $user = Fixtures::user($role);
        $perm = new PermissionService();

        $allowed = $perm->allowedOperations($user['id']);
        sort($allowed);
        sort($expected);
        $this->assertSame($expected, $allowed);

        foreach ($expected as $op) {
            $this->assertTrue($perm->can($user['id'], $op), "{$role} should be able to {$op}");
        }
        $this->assertFalse($perm->can($user['id'], 'nonexistent.operation'));
    }

    /** isAdmin platí jen pro roli administrátor. */
    public function testOnlyAdministratorIsAdmin(): void
    {
        $perm = new PermissionService();
        $this->assertTrue($perm->isAdmin(Fixtures::user('administrator')['id']));
        $this->assertFalse($perm->isAdmin(Fixtures::user('organizer')['id']));
    }

    /** Uživatel bez role nemá aktivní roli ani žádné oprávnění. */
    public function testUserWithoutRolesCanDoNothing(): void
    {
        $user = Fixtures::user([]);
        $perm = new PermissionService();

        $this->assertNull($perm->activeRole($user['id']));
        $this->assertFalse($perm->can($user['id'], 'action.view_basic'));
        $this->assertSame([], $perm->allowedOperations($user['id']));
    }

    /** Oprávnění se řídí jen aktivní rolí; po přepnutí na admina se práva admina zpřístupní. */
    public function testPermissionsFollowTheActiveRoleOnly(): void
    {
        $user = Fixtures::user(['viewer', 'administrator']);
        $perm = new PermissionService();

        $this->assertFalse($perm->can($user['id'], 'users.manage'), 'viewer is active, admin rights must not leak in');

        $this->assertTrue($perm->switchRole($user['id'], Fixtures::roleId('administrator')));
        $this->assertTrue($perm->can($user['id'], 'users.manage'));
    }

    /** Nelze se přepnout do role, kterou uživatel nemá přidělenou. */
    public function testCannotSwitchToRoleNotAssigned(): void
    {
        $user = Fixtures::user('viewer');
        $perm = new PermissionService();

        $this->assertFalse($perm->switchRole($user['id'], Fixtures::roleId('administrator')));
        $this->assertSame('viewer', $perm->activeRole($user['id'])['code']);
    }

    /** Když je aktivní role uživateli odebrána, přepne se na jinou jeho roli a práva odebrané role ztratí. */
    public function testActiveRoleFallsBackWhenItWasRemoved(): void
    {
        $user = Fixtures::user(['administrator', 'viewer']);
        $this->pdo->prepare('UPDATE user_roles SET deactivated_at = NOW() WHERE user_id = ? AND role_id = ?')
            ->execute([$user['id'], Fixtures::roleId('administrator')]);

        $perm = new PermissionService();
        $this->assertSame('viewer', $perm->activeRole($user['id'])['code']);
        $this->assertFalse($perm->can($user['id'], 'users.manage'));
        $this->assertSame(Fixtures::roleId('viewer'), (int) $this->scalar('SELECT active_role_id FROM app_users WHERE id = ?', [$user['id']]));
    }

    /** Oprávnění odebrané roli (deaktivované v role_operations) přestane platit. */
    public function testRevokedOperationIsNoLongerAllowed(): void
    {
        $user = Fixtures::user('organizer');
        $this->pdo->prepare(
            "UPDATE role_operations SET deactivated_at = NOW()
             WHERE role_id = ? AND operation_id = (SELECT id FROM operations WHERE code = 'action.create')"
        )->execute([Fixtures::roleId('organizer')]);

        $this->assertFalse((new PermissionService())->can($user['id'], 'action.create'));
    }
}
