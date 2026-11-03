<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\StatisticsRepository;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;
use Tests\Support\StatisticsDataset as D;

/** Numbers of the "Statistika" report — see StatisticsDataset for how each expected value adds up. */
final class StatisticsRepositoryTest extends DatabaseTestCase
{
    private const KULTURNI_KOMUNITNI = ['Kulturní', 'Kultura', 'Sociální', 'Komunitní'];
    private const VZDELAVACI         = ['Vzdělávací', 'Vzdělávání', 'Vzdělávaci'];

    private StatisticsRepository $stats;

    protected function setUp(): void
    {
        parent::setUp();
        D::build();
        $this->stats = new StatisticsRepository();
    }

    /** Počty akcí ve Statistice podle typu, provedení (Online), tématu (ICT) a „nejsme hlavní pořadatel“. */
    public function testActionCounts(): void
    {
        $this->assertSame(3, $this->stats->countActionsByTypes(self::KULTURNI_KOMUNITNI, D::FROM, D::TO), 'K1 + V2 + NM');
        $this->assertSame(2, $this->stats->countActionsByTypes(self::VZDELAVACI, D::FROM, D::TO), 'V1 + V2');
        $this->assertSame(1, $this->stats->countActionsByTypes(['Výstava'], D::FROM, D::TO));
        $this->assertSame(1, $this->stats->countActionsByTypesAndFormat(self::VZDELAVACI, 'Online', D::FROM, D::TO));
        $this->assertSame(1, $this->stats->countActionsByTypesAndTopic(self::VZDELAVACI, 'Informační technologie', D::FROM, D::TO));
        $this->assertSame(1, $this->stats->countActionsByNotMainOrganizer(D::FROM, D::TO));
    }

    /** Součty hodin vzdělávacích akcí (celkem, online, ICT). */
    public function testDurations(): void
    {
        $this->assertSame(5400 + 10800, $this->stats->sumPublicSecondsByTypes(self::VZDELAVACI, D::FROM, D::TO), '1.5 h + 3 h');
        $this->assertSame(5400, $this->stats->sumPublicSecondsByTypesAndFormat(self::VZDELAVACI, 'Online', D::FROM, D::TO));
        $this->assertSame(5400, $this->stats->sumPublicSecondsByTypesAndTopic(self::VZDELAVACI, 'Informační technologie', D::FROM, D::TO));
    }

    /** Součty návštěvnosti – celkem, podle typu, jen platící, podle kategorie; zrušené a mimo období se nepočítají. */
    public function testAttendance(): void
    {
        $this->assertSame(30 + 15 + 5 + 7, $this->stats->sumAttendanceByTypes([], D::FROM, D::TO), 'all actions, cancelled and next year left out');
        $this->assertSame(30 + 5 + 7, $this->stats->sumAttendanceByTypes(self::KULTURNI_KOMUNITNI, D::FROM, D::TO));
        $this->assertSame(20 + 5, $this->stats->sumAttendanceByTypes(self::KULTURNI_KOMUNITNI, D::FROM, D::TO, 'Platící'));
        $this->assertSame(15 + 5, $this->stats->sumAttendanceByTypes(self::VZDELAVACI, D::FROM, D::TO));
        $this->assertSame(5, $this->stats->sumAttendanceByTypes(self::VZDELAVACI, D::FROM, D::TO, 'Platící'));
        $this->assertSame(15, $this->stats->sumAttendanceByTypesAndVisitorCategory(self::VZDELAVACI, 'Žáci 1. st. ZŠ', D::FROM, D::TO));
        $this->assertSame(7, $this->stats->sumAttendanceByNotMainOrganizer(D::FROM, D::TO));
    }

    /** Příjmy ze vstupného (jen CZK, jen Vstupné) a výdaje na honoráře a propagaci. */
    public function testMoney(): void
    {
        $this->assertSame(1700.0, $this->stats->sumIncomeVstupneByTypes([], D::FROM, D::TO), 'CZK Vstupné only: 1500 + 200');
        $this->assertSame(1500.0, $this->stats->sumIncomeVstupneByTypes(['Kulturní', 'Kultura'], D::FROM, D::TO));
        $this->assertSame(200.0, $this->stats->sumIncomeVstupneByTypes(self::VZDELAVACI, D::FROM, D::TO));
        $this->assertSame(3000.0, $this->stats->sumCostByObjectName('Honorář', D::FROM, D::TO));
        $this->assertSame(500.0, $this->stats->sumCostByObjectName('Propagace', D::FROM, D::TO));
    }

    /** Koncové datum období se počítá včetně celého dne. */
    public function testDateRangeIsInclusiveOfTheLastDay(): void
    {
        $this->assertSame(1, $this->stats->countActionsByTypes(['Výstava'], '2030-06-01', '2030-06-01'));
        $this->assertSame(0, $this->stats->countActionsByTypes(['Výstava'], '2030-06-02', '2030-06-30'));
    }

    /** Chybějící položka číselníku dá prázdnou hodnotu (null), ne zavádějící nulu; existující nepoužitá dá 0. */
    public function testMissingDictionaryEntryGivesNullNotZero(): void
    {
        Fixtures::dict('action_types', 'Nepoužitý typ');

        $this->assertSame(0, $this->stats->countActionsByTypes(['Nepoužitý typ'], D::FROM, D::TO), 'existing but unused = real zero');
        $this->assertNull($this->stats->countActionsByTypes(['Neexistující typ'], D::FROM, D::TO));
        $this->assertNull($this->stats->countActionsByTypesAndTopic(self::VZDELAVACI, 'Neexistující téma', D::FROM, D::TO));
        $this->assertNull($this->stats->sumAttendanceByTypesAndVisitorCategory(self::VZDELAVACI, 'Neexistující kategorie', D::FROM, D::TO));
        $this->assertNull($this->stats->sumCostByObjectName('Neexistující položka', D::FROM, D::TO));
    }
}
