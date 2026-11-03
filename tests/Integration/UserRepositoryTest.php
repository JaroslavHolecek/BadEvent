<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\UserRepository;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;

final class UserRepositoryTest extends DatabaseTestCase
{
    private function newLogin(string $email): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO bl_login (email) VALUES (?) RETURNING id');
        $stmt->execute([$email]);
        return (int) $stmt->fetchColumn();
    }

    /** První registrovaný uživatel dostane roli administrátor, další už ne; vytvoří se i osoba. */
    public function testVeryFirstRegisteredUserBecomesAdministrator(): void
    {
        $repo   = new UserRepository();
        $first  = $this->newLogin('prvni@example.test');
        $second = $this->newLogin('druhy@example.test');

        $repo->registerNewUser($first, 'První Uživatel');
        $repo->registerNewUser($second, 'Druhý Uživatel');

        $this->assertTrue($repo->hasRoleCode($first, 'administrator'));
        $this->assertFalse($repo->hasRoleCode($second, 'administrator'));
        $this->assertSame('Druhý Uživatel', $this->scalar(
            'SELECT p.full_name FROM app_users u JOIN persons p ON p.id = u.person_id WHERE u.id = ?', [$second]
        ));
    }

    /** isLastAdmin správně pozná posledního administrátora (i po odebrání role druhému). */
    public function testIsLastAdmin(): void
    {
        $repo   = new UserRepository();
        $admin1 = Fixtures::user('administrator');

        $this->assertTrue($repo->isLastAdmin($admin1['id']));

        $admin2 = Fixtures::user('administrator');
        $this->assertFalse($repo->isLastAdmin($admin1['id']));

        $repo->removeRole($admin2['id'], Fixtures::roleId('administrator'));
        $this->assertTrue($repo->isLastAdmin($admin1['id']));
    }

    /** Opětovné přidělení odebrané role ji znovu aktivuje. */
    public function testAssignRoleReactivatesARemovedRole(): void
    {
        $repo = new UserRepository();
        $user = Fixtures::user('organizer');

        $repo->removeRole($user['id'], Fixtures::roleId('organizer'));
        $this->assertFalse($repo->hasRoleCode($user['id'], 'organizer'));

        $repo->assignRole($user['id'], Fixtures::roleId('organizer'));
        $this->assertTrue($repo->hasRoleCode($user['id'], 'organizer'));
    }

    /** Smazání uživatele anonymizuje přihlašovací účet (email, heslo) a odpojí osobu. */
    public function testSoftDeleteAnonymizesLoginAndDetachesPerson(): void
    {
        $repo = new UserRepository();
        $user = Fixtures::user('organizer', 'Jan Novák');

        $repo->softDelete($user['id']);

        $login = $this->pdo->query("SELECT email, password_hash, deleted_at FROM bl_login WHERE id = {$user['id']}")->fetch();
        $this->assertSame('deleted_email_' . $user['id'], $login['email']);
        $this->assertNull($login['password_hash']);
        $this->assertNotNull($login['deleted_at']);
        $this->assertNull($this->scalar('SELECT person_id FROM app_users WHERE id = ?', [$user['id']]));
    }

    /** Výběr kandidátů podle role (organizátor apod.) vynechá deaktivované a smazané uživatele. */
    public function testRoleCandidateListsSkipDeactivatedAndDeletedUsers(): void
    {
        $repo        = new UserRepository();
        $active      = Fixtures::user('organizer', 'Aktivní');
        $deactivated = Fixtures::user('organizer', 'Deaktivovaný');
        $deleted     = Fixtures::user('organizer', 'Smazaný');
        Fixtures::user('viewer', 'Jiná role');

        $repo->deactivate($deactivated['id']);
        $repo->softDelete($deleted['id']);

        $this->assertSame([$active['id']], array_map('intval', array_column($repo->listByRoleCode('organizer'), 'id')));
    }
}
