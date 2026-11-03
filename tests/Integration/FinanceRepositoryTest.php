<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\FinanceRepository;
use PDOException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;

final class FinanceRepositoryTest extends DatabaseTestCase
{
    /** Součty příjmů a výdajů akce jsou zvlášť pro každou měnu. */
    public function testTotalsArePerCurrencyNeverMixed(): void
    {
        $action = Fixtures::action();
        Fixtures::income($action, 'Vstupné', '100.50');
        Fixtures::income($action, 'Dar', '200.00');
        Fixtures::income($action, 'Vstupné', '10.00', 'EUR');
        Fixtures::cost($action, 'DPP', 'Lektor', '999.99');

        $finance = new FinanceRepository();

        $this->assertSame(
            [['code' => 'CZK', 'symbol' => 'Kč', 'total' => '300.50'], ['code' => 'EUR', 'symbol' => '€', 'total' => '10.00']],
            $finance->incomeTotals($action)
        );
        $this->assertSame([['code' => 'CZK', 'symbol' => 'Kč', 'total' => '999.99']], $finance->costTotals($action));
    }

    /** Součty události sečtou všechny její akce (celkem i podle typu příjmu), cizí akce ne. */
    public function testEventTotalsAddUpAllItsActions(): void
    {
        $event = Fixtures::event('Festival');
        $a = Fixtures::action(['event_id' => $event]);
        $b = Fixtures::action(['event_id' => $event]);
        $other = Fixtures::action();
        Fixtures::income($a, 'Vstupné', '100.00');
        Fixtures::income($b, 'Vstupné', '50.00');
        Fixtures::income($b, 'Dar', '25.00');
        Fixtures::income($other, 'Vstupné', '1000.00');

        $finance = new FinanceRepository();

        $this->assertSame('175.00', $finance->eventIncomeGrandTotals($event)[0]['total']);
        $byType = array_column($finance->eventIncomeTotalsByType($event), 'total', 'type_name');
        $this->assertSame(['Dar' => '25.00', 'Vstupné' => '150.00'], $byType);
    }

    /** Uložení ekonomických údajů nahradí všechny předchozí příjmy akce. */
    public function testReplaceIncomesReplacesTheWholeSet(): void
    {
        $action  = Fixtures::action();
        $finance = new FinanceRepository();
        Fixtures::income($action, 'Dar', '1.00');

        $finance->replaceIncomes($action, [[
            'type_id' => Fixtures::dict('income_types', 'Vstupné'), 'subtype_id' => null, 'amount' => '80',
            'currency_id' => Fixtures::currencyId('CZK'), 'reference_number' => 'F-1', 'note' => null,
        ]]);

        $rows = $finance->listIncomes($action);
        $this->assertCount(1, $rows);
        $this->assertSame('80.00', $rows[0]['amount']);
        $this->assertSame('F-1', $rows[0]['reference_number']);
    }

    /** Podtyp příjmu musí patřit ke zvolenému typu (hlídá databáze). */
    public function testIncomeSubtypeMustBelongToItsType(): void
    {
        $action    = Fixtures::action();
        $subtypeOfVstupne = (int) $this->scalar("SELECT s.id FROM income_subtypes s JOIN income_types t ON t.id = s.income_type_id WHERE t.name = 'Vstupné' LIMIT 1");

        $this->expectException(PDOException::class);
        (new FinanceRepository())->replaceIncomes($action, [[
            'type_id' => Fixtures::dict('income_types', 'Dar'), 'subtype_id' => $subtypeOfVstupne, 'amount' => '10',
            'currency_id' => Fixtures::currencyId('CZK'), 'reference_number' => null, 'note' => null,
        ]]);
    }
}
