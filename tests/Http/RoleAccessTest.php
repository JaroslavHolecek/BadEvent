<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Fixtures;
use Tests\Support\HttpTestCase;

/** Which pages each seeded role can open — the role → page matrix as users experience it. */
final class RoleAccessTest extends HttpTestCase
{
    private const ALL = ['administrator', 'organizer', 'substitute', 'stuff', 'viewer', 'place_manager'];

    /** page => roles that may open it */
    private const PAGES = [
        '/'                          => self::ALL,
        '/actions'                   => self::ALL,
        '/events'                    => self::ALL,
        '/calendar/places'           => self::ALL,
        '/profile'                   => self::ALL,
        '/actions/create'            => ['administrator', 'organizer'],
        '/people'                    => ['administrator', 'organizer'],
        '/admin/roles'               => ['administrator'],
        '/admin/dictionaries'        => ['administrator'],
        '/admin/building-code-duty'  => ['administrator'],
        '/export'                    => ['administrator'],
        '/export/print'              => ['administrator'],
        '/export/statistika/print'   => ['administrator'],
        '/export/rooms/print'        => ['administrator'],
        '/export/maintenance/print'  => ['administrator'],
        '/export/weekly/print'       => ['administrator'],
    ];

    public static function roles(): array
    {
        return array_combine(self::ALL, array_map(fn($r) => [$r], self::ALL));
    }

    /** Pro danou roli ověří, které stránky otevře a ze kterých je přesměrován (matice role × stránka). */
    #[DataProvider('roles')]
    public function testPagesAccessibleToRole(string $role): void
    {
        // Some content, so the pages render real rows, not just empty states.
        $place  = Fixtures::place('Sál');
        $action = Fixtures::action(['name' => 'Ukázková akce', 'types' => ['Kulturní']]);
        Fixtures::confirm($action, $place);

        $this->loginAs($role);

        foreach (self::PAGES as $path => $allowedRoles) {
            $response = $this->http->get($path);
            if (in_array($role, $allowedRoles, true)) {
                $this->assertOk($response, "{$role} should open {$path}");
            } else {
                $this->assertDenied($response, "{$role} must not open {$path}");
            }
        }

        $this->assertOk($this->http->get('/actions/' . $action), "{$role} can view an action's detail");
    }

    /** Přepnutí role v hlavičce změní přístup ke stránkám. */
    public function testSwitchingRoleChangesAccess(): void
    {
        $this->loginAs(['viewer', 'administrator']);
        $this->assertDenied($this->http->get('/admin/roles'));

        $this->http->post('/role/switch/' . Fixtures::roleId('administrator'), ['return_to' => '/admin/roles']);

        $this->assertOk($this->http->get('/admin/roles'));
    }

    /** Podvržené přepnutí do nepřidělené role nic nezpřístupní. */
    public function testCannotSwitchToRoleNotAssigned(): void
    {
        $this->loginAs('viewer');

        $this->http->post('/role/switch/' . Fixtures::roleId('administrator'));

        $this->assertDenied($this->http->get('/admin/roles'));
    }

    /** Neexistující stránka i neexistující akce vrátí 404. */
    public function testUnknownPageIs404(): void
    {
        $this->loginAs('administrator');
        $this->assertSame(404, $this->http->get('/neexistuje')->status);
        $this->assertSame(404, $this->http->get('/actions/999999')->status);
    }
}
