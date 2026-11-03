<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use InvalidArgumentException;
use PDO;

/**
 * Generic CRUD (create + list + deactivate) for the simple name/abbreviation
 * lookup tables used across the app, plus dedicated handling for currencies
 * and income subtypes, whose shape differs.
 */
final class DictionaryRepository
{
    private PDO $pdo;

    private const TABLES = [
        'contact_types'      => [
            'label'            => 'Typy kontaktů',
            'has_abbreviation' => true,
            'help'             => 'Způsoby kontaktování osoby, např. Telefon, Email, Discord. Nepatří sem konkrétní kontaktní údaje (ty se zadávají u osoby) ani role osoby.',
        ],
        'action_types'       => [
            'label'            => 'Typy akcí',
            'has_abbreviation' => true,
            'help'             => 'Obsahová kategorizace akcí, např. Kulturní, Vzdělávací, Sociální — používá se k třídění a filtrování akcí. Nepatří sem stav akce (ten má vlastní neměnný číselník) ani konkrétní název akce.',
        ],
        'action_topics'      => [
            'label'            => 'Témata akcí',
            'has_abbreviation' => true,
            'help'             => 'Náměty/témata akcí, nezávislé na Typu akce, např. Příroda, Historie, Řemesla. Slouží k dalšímu, nezávislému třídění akcí vedle Typu akce.',
        ],
        'action_formats'     => [
            'label'            => 'Provedení akcí',
            'has_abbreviation' => true,
            'help'             => 'Jak akce probíhá, např. Online, Prezenčně, Hybridně — nezávislé na Typu i Tématu akce.',
        ],
        'visitor_types'      => [
            'label'            => 'Typy návštěvníků',
            'has_abbreviation' => false,
            'help'             => 'Rozlišení návštěvníků podle vztahu k akci, např. Platící, Neplatící. Nepatří sem věková ani jiná demografická kategorizace (ta je v Kategoriích návštěvníků).',
        ],
        'visitor_categories' => [
            'label'            => 'Kategorie návštěvníků',
            'has_abbreviation' => false,
            'help'             => 'Demografické/cílové skupiny návštěvníků, např. Děti, Mládež, Dospělí, Senioři. Nepatří sem typ platby ani jiné vlastnosti návštěvy.',
        ],
        'cost_types'         => [
            'label'            => 'Typy nákladů',
            'has_abbreviation' => false,
            'help'             => 'Kategorie výdajů spojených s akcí, např. DPP, Faktura, Materiál, Služba. Nepatří sem konkrétní částky ani doklady (ty se zadávají u jednotlivého výdaje).',
        ],
        'cost_objects'       => [
            'label'            => 'Náklady — za co',
            'has_abbreviation' => false,
            'help'             => 'Předmět nákladu, tedy za co bylo placeno, např. Jídlo, Osvětlení, Propagace. Nezávisí na typu nákladu — u každého výdaje se zadává typ i „za co“ zvlášť.',
        ],
        'income_types'       => [
            'label'            => 'Typy příjmů',
            'has_abbreviation' => false,
            'help'             => 'Kategorie příjmů spojených s akcí, např. Vstupné, Dotace, Dar. Podrobnější členění (Web, Hotovost, Faktura apod.) patří do podtypů u konkrétního typu příjmu, ne sem.',
        ],
    ];

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    /** @return array<string, array{label: string, has_abbreviation: bool}> */
    public static function types(): array
    {
        return self::TABLES;
    }

    public static function hasAbbreviation(string $table): bool
    {
        self::assertKnownTable($table);
        return self::TABLES[$table]['has_abbreviation'];
    }

    public function listAll(string $table): array
    {
        self::assertKnownTable($table);
        return $this->pdo->query("SELECT * FROM {$table} ORDER BY name ASC")->fetchAll();
    }

    /** Returns only non-deactivated entries, for select/checkbox pickers. */
    public function listActive(string $table): array
    {
        self::assertKnownTable($table);
        return $this->pdo->query("SELECT * FROM {$table} WHERE deactivated_at IS NULL ORDER BY name ASC")->fetchAll();
    }

    public function create(string $table, string $name, ?string $abbreviation = null): int
    {
        self::assertKnownTable($table);

        if (self::TABLES[$table]['has_abbreviation']) {
            $sql    = "INSERT INTO {$table} (name, abbreviation) VALUES (:name, :abbreviation) RETURNING id";
            $params = [':name' => $name, ':abbreviation' => $abbreviation];
        } else {
            $sql    = "INSERT INTO {$table} (name) VALUES (:name) RETURNING id";
            $params = [':name' => $name];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function update(string $table, int $id, string $name, ?string $abbreviation = null): void
    {
        self::assertKnownTable($table);

        if (self::TABLES[$table]['has_abbreviation']) {
            $sql    = "UPDATE {$table} SET name = :name, abbreviation = :abbreviation WHERE id = :id";
            $params = [':name' => $name, ':abbreviation' => $abbreviation, ':id' => $id];
        } else {
            $sql    = "UPDATE {$table} SET name = :name WHERE id = :id";
            $params = [':name' => $name, ':id' => $id];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function deactivate(string $table, int $id): void
    {
        self::assertKnownTable($table);
        $stmt = $this->pdo->prepare("UPDATE {$table} SET deactivated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    public function activate(string $table, int $id): void
    {
        self::assertKnownTable($table);
        $stmt = $this->pdo->prepare("UPDATE {$table} SET deactivated_at = NULL WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    public function listCurrencies(): array
    {
        return $this->pdo->query('SELECT * FROM currencies ORDER BY name ASC')->fetchAll();
    }

    public function createCurrency(string $code, string $name, ?string $symbol, int $decimalPlaces): int
    {
        $sql = <<<SQL
            INSERT INTO currencies (code, name, symbol, decimal_places)
            VALUES (:code, :name, :symbol, :decimal_places)
            RETURNING id
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':code'           => $code,
            ':name'           => $name,
            ':symbol'         => $symbol,
            ':decimal_places' => $decimalPlaces,
        ]);
        return (int) $stmt->fetchColumn();
    }

    public function deactivateCurrency(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE currencies SET deactivated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function activateCurrency(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE currencies SET deactivated_at = NULL WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function listIncomeSubtypes(int $incomeTypeId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM income_subtypes WHERE income_type_id = :income_type_id ORDER BY name ASC');
        $stmt->execute([':income_type_id' => $incomeTypeId]);
        return $stmt->fetchAll();
    }

    public function createIncomeSubtype(int $incomeTypeId, string $name): int
    {
        $sql = 'INSERT INTO income_subtypes (income_type_id, name) VALUES (:income_type_id, :name) RETURNING id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':income_type_id' => $incomeTypeId, ':name' => $name]);
        return (int) $stmt->fetchColumn();
    }

    public function updateIncomeSubtype(int $id, string $name): void
    {
        $stmt = $this->pdo->prepare('UPDATE income_subtypes SET name = :name WHERE id = :id');
        $stmt->execute([':name' => $name, ':id' => $id]);
    }

    public function deactivateIncomeSubtype(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE income_subtypes SET deactivated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function activateIncomeSubtype(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE income_subtypes SET deactivated_at = NULL WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    private static function assertKnownTable(string $table): void
    {
        if (!isset(self::TABLES[$table])) {
            throw new InvalidArgumentException("Unknown dictionary table: {$table}");
        }
    }
}
