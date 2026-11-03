<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\ActionRepository;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;

/** Filtering behind the general export (ActionRepository::listForExport): OR inside one filter, AND between filters. */
final class ExportFilterTest extends DatabaseTestCase
{
    private array $ids;
    private array $alice;
    private array $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = Fixtures::user('organizer', 'Alice');
        $this->bob   = Fixtures::user('organizer', 'Bob');

        $this->ids = [
            'kult_alice' => Fixtures::action(['name' => 'kult_alice', 'types' => ['Kulturní'], 'categories' => ['Děti'],
                'organizers' => [$this->alice['id']], 'public_start_at' => '2030-03-01 10:00:00']),
            'vzdel_bob' => Fixtures::action(['name' => 'vzdel_bob', 'types' => ['Vzdělávací'], 'categories' => ['Senioři'],
                'organizers' => [$this->bob['id']], 'public_start_at' => '2030-03-15 10:00:00', 'status' => 'confirmed']),
            'soc_both' => Fixtures::action(['name' => 'soc_both', 'types' => ['Sociální'], 'categories' => ['Děti', 'Senioři'],
                'organizers' => [$this->alice['id'], $this->bob['id']], 'public_start_at' => '2030-04-10 10:00:00']),
            'later' => Fixtures::action(['name' => 'later', 'types' => ['Kulturní'], 'public_start_at' => '2030-09-01 10:00:00']),
        ];
    }

    private function names(array $types = [], array $categories = [], array $organizers = [], ?string $from = null, ?string $to = null, array $statuses = []): array
    {
        $rows = (new ActionRepository())->listForExport(
            array_map(fn($n) => Fixtures::dict('action_types', $n), $types),
            array_map(fn($n) => Fixtures::dict('visitor_categories', $n), $categories),
            $organizers,
            $from,
            $to,
            array_map(fn($c) => Fixtures::statusId($c), $statuses)
        );
        return array_column($rows, 'name');
    }

    /** Export bez filtru vrátí všechny akce seřazené podle začátku. */
    public function testNoFilterReturnsEverythingSortedByStart(): void
    {
        $this->assertSame(['kult_alice', 'vzdel_bob', 'soc_both', 'later'], $this->names());
    }

    /** Více zaškrtnutých hodnot v jednom filtru = stačí shoda s kteroukoli (NEBO). */
    public function testCheckedValuesInOneFilterAreOred(): void
    {
        $this->assertSame(['kult_alice', 'vzdel_bob', 'later'], $this->names(types: ['Kulturní', 'Vzdělávací']));
    }

    /** Různé filtry se kombinují současně (A). */
    public function testDifferentFiltersAreAnded(): void
    {
        $this->assertSame(['soc_both'], $this->names(categories: ['Děti'], organizers: [$this->bob['id']]));
    }

    /** Filtr podle období zahrnuje akce, které do období zasahují. */
    public function testDateRange(): void
    {
        $this->assertSame(['vzdel_bob', 'soc_both'], $this->names(from: '2030-03-10', to: '2030-04-30 23:59:59'));
    }

    /** Filtr podle stavu akce. */
    public function testStatusFilter(): void
    {
        $this->assertSame(['vzdel_bob'], $this->names(statuses: ['confirmed']));
    }

    /** Odebraný organizátor už ve filtru podle organizátora akci nenajde. */
    public function testRemovedOrganizerNoLongerMatches(): void
    {
        (new ActionRepository())->removeOrganizer($this->ids['soc_both'], $this->bob['id']);

        $this->assertSame(['vzdel_bob'], $this->names(organizers: [$this->bob['id']]));
    }
}
