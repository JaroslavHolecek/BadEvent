<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * One fixed year (2030) of actions with known attendance/income/cost, used to check the numbers in
 * the "Statistika" report both directly (StatisticsRepository) and as rendered (/export/statistika/*).
 * Expected values are worked out by hand in the comments.
 */
final class StatisticsDataset
{
    public const FROM = '2030-01-01';
    public const TO   = '2030-12-31';

    /** @return array<string, int> action ids by key */
    public static function build(): array
    {
        $ids = [];

        // Kulturní, 2 h, 30 visitors (20 paying), Vstupné 1500 CZK + 10 EUR (EUR not counted) + Dotace (not Vstupné), Honorář 3000, Propagace 500
        $ids['K1'] = Fixtures::action(['name' => 'Koncert', 'types' => ['Kulturní'],
            'public_start_at' => '2030-03-10 17:00:00', 'public_end_at' => '2030-03-10 19:00:00']);
        Fixtures::attendance($ids['K1'], 'Platící', 'Dospělí', 20);
        Fixtures::attendance($ids['K1'], 'Neplatící', 'Děti', 10);
        Fixtures::income($ids['K1'], 'Vstupné', '1500.00');
        Fixtures::income($ids['K1'], 'Vstupné', '10.00', 'EUR');
        Fixtures::income($ids['K1'], 'Dotace', '5000.00');
        Fixtures::cost($ids['K1'], 'Faktura', 'Honorář', '3000.00');
        Fixtures::cost($ids['K1'], 'Faktura', 'Propagace', '500.00');

        // Vzdělávací, online, ICT topic, 1.5 h, 15 pupils (non-paying), Vstupné 200 CZK
        $ids['V1'] = Fixtures::action(['name' => 'Kurz online', 'types' => ['Vzdělávací'], 'formats' => ['Online'], 'topics' => ['Informační technologie'],
            'public_start_at' => '2030-04-01 10:00:00', 'public_end_at' => '2030-04-01 11:30:00']);
        Fixtures::attendance($ids['V1'], 'Neplatící', 'Žáci 1. st. ZŠ', 15);
        Fixtures::income($ids['V1'], 'Vstupné', '200.00');

        // Vzdělávací AND Kulturní (counts in both buckets), 3 h, 5 paying adults
        $ids['V2'] = Fixtures::action(['name' => 'Přednáška s hudbou', 'types' => ['Vzdělávací', 'Kulturní'],
            'public_start_at' => '2030-05-05 09:00:00', 'public_end_at' => '2030-05-05 12:00:00']);
        Fixtures::attendance($ids['V2'], 'Platící', 'Dospělí', 5);

        // Výstava, no attendance
        $ids['X'] = Fixtures::action(['name' => 'Výstava fotografií', 'types' => ['Výstava'],
            'public_start_at' => '2030-06-01 09:00:00', 'public_end_at' => '2030-06-01 18:00:00']);

        // Sociální, library is not the main organizer, 7 visitors
        $ids['NM'] = Fixtures::action(['name' => 'Komunitní setkání', 'types' => ['Sociální'], 'not_main_organizer' => true,
            'public_start_at' => '2030-07-01 16:00:00', 'public_end_at' => '2030-07-01 17:00:00']);
        Fixtures::attendance($ids['NM'], 'Neplatící', 'Dospělí', 7);

        // Must NOT be counted: cancelled, and outside the year
        $ids['CANCELLED'] = Fixtures::action(['name' => 'Zrušený koncert', 'types' => ['Kulturní'], 'status' => 'cancelled',
            'public_start_at' => '2030-03-11 17:00:00', 'public_end_at' => '2030-03-11 19:00:00']);
        Fixtures::attendance($ids['CANCELLED'], 'Platící', 'Dospělí', 100);
        Fixtures::income($ids['CANCELLED'], 'Vstupné', '999.00');

        $ids['NEXT_YEAR'] = Fixtures::action(['name' => 'Příští rok', 'types' => ['Kulturní'],
            'public_start_at' => '2031-01-05 17:00:00', 'public_end_at' => '2031-01-05 19:00:00']);
        Fixtures::attendance($ids['NEXT_YEAR'], 'Platící', 'Dospělí', 50);

        return $ids;
    }
}
