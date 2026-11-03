<?php

declare(strict_types=1);

namespace Tests\Support;

use InvalidArgumentException;
use PDO;

/**
 * Test data builders — plain SQL inserts, so a test sets up exactly the state it needs without
 * going through the code under test. Dictionary entries are referenced by name and created on
 * demand when the seed doesn't have them (e.g. 'Online', 'Honorář').
 */
final class Fixtures
{
    public const PASSWORD = 'Testovaci-heslo-123';

    private const DICTIONARIES = [
        'action_types', 'action_topics', 'action_formats', 'visitor_types', 'visitor_categories',
        'income_types', 'cost_types', 'cost_objects', 'contact_types',
    ];

    private static int $seq = 0;

    private static function pdo(): PDO
    {
        return TestDatabase::pdo();
    }

    private static function insert(string $sql, array $params): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** 'Y-m-d H:i:s' relative to now, e.g. Fixtures::at('+10 days 17:00'). */
    public static function at(string $relative): string
    {
        return date('Y-m-d H:i:s', strtotime($relative));
    }

    /**
     * Login account (bl_login, password Fixtures::PASSWORD) + person + app user with the given roles;
     * the first role is the active one.
     *
     * @param string|string[] $roles role codes, e.g. 'organizer' or ['organizer', 'administrator']
     * @return array{id: int, person_id: int, email: string, password: string, full_name: string}
     */
    public static function user(string|array $roles = [], ?string $fullName = null, ?string $shortName = null, ?string $email = null): array
    {
        $n         = ++self::$seq;
        $roles     = (array) $roles;
        $email   ??= "uzivatel{$n}@example.test";
        $fullName ??= "Uživatel {$n}";

        $userId = self::insert(
            'INSERT INTO bl_login (email, password_hash) VALUES (?, ?) RETURNING id',
            [$email, password_hash(self::PASSWORD, PASSWORD_DEFAULT)]
        );
        $personId = self::insert(
            'INSERT INTO persons (full_name, short_name) VALUES (?, ?) RETURNING id',
            [$fullName, $shortName]
        );
        self::pdo()->prepare('INSERT INTO app_users (id, person_id) VALUES (?, ?)')->execute([$userId, $personId]);

        foreach ($roles as $code) {
            self::pdo()->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, self::roleId($code)]);
        }
        if ($roles !== []) {
            self::pdo()->prepare('UPDATE app_users SET active_role_id = ? WHERE id = ?')->execute([self::roleId($roles[0]), $userId]);
        }

        return ['id' => $userId, 'person_id' => $personId, 'email' => $email, 'password' => self::PASSWORD, 'full_name' => $fullName];
    }

    public static function roleId(string $code): int
    {
        return self::lookup('SELECT id FROM roles WHERE code = ?', $code);
    }

    public static function statusId(string $code): int
    {
        return self::lookup('SELECT id FROM action_statuses WHERE code = ?', $code);
    }

    public static function currencyId(string $code): int
    {
        return self::lookup('SELECT id FROM currencies WHERE code = ?', $code);
    }

    private static function lookup(string $sql, string $value): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute([$value]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new InvalidArgumentException("Fixture lookup found nothing for '{$value}'.");
        }
        return (int) $id;
    }

    /** Id of a dictionary entry by name, creating it when missing. */
    public static function dict(string $table, string $name): int
    {
        if (!in_array($table, self::DICTIONARIES, true)) {
            throw new InvalidArgumentException("Unknown dictionary table: {$table}");
        }
        return self::insert(
            "INSERT INTO {$table} (name) VALUES (?) ON CONFLICT (name) DO UPDATE SET name = EXCLUDED.name RETURNING id",
            [$name]
        );
    }

    public static function place(string $name, ?string $abbreviation = null, ?string $note = null): int
    {
        return self::insert(
            'INSERT INTO places (name, abbreviation, note) VALUES (?, ?, ?) RETURNING id',
            [$name, $abbreviation, $note]
        );
    }

    public static function event(string $name = 'Testovací událost'): int
    {
        return self::insert('INSERT INTO events (name) VALUES (?) RETURNING id', [$name]);
    }

    /**
     * An action with sensible defaults: public 17:00–19:00 ten days ahead, staff from 30 minutes
     * before to 30 minutes after, status draft. Any date can be passed as null explicitly.
     *
     * Options: name, event_id, public_start_at, public_end_at, staff_start_at, staff_end_at, status,
     * organizers (user ids), substitutes (user ids), staff (person ids), types / topics / formats /
     * categories (dictionary names), not_main_organizer, photograph, photograph_note, capacity, note.
     */
    public static function action(array $o = []): int
    {
        $start = array_key_exists('public_start_at', $o) ? $o['public_start_at'] : self::at('+10 days 17:00');
        $end   = array_key_exists('public_end_at', $o)
            ? $o['public_end_at']
            : ($start !== null ? date('Y-m-d H:i:s', strtotime($start . ' +2 hours')) : null);
        $staffStart = array_key_exists('staff_start_at', $o)
            ? $o['staff_start_at']
            : ($start !== null ? date('Y-m-d H:i:s', strtotime($start . ' -30 minutes')) : null);
        $staffEnd = array_key_exists('staff_end_at', $o)
            ? $o['staff_end_at']
            : ($end !== null ? date('Y-m-d H:i:s', strtotime($end . ' +30 minutes')) : null);

        $actionId = self::insert(
            'INSERT INTO actions (event_id, name, staff_start_at, staff_end_at, public_start_at, public_end_at,
                                  capacity, note, photograph, photograph_note, not_main_organizer, actual_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [
                $o['event_id'] ?? self::event(),
                $o['name'] ?? 'Akce ' . (++self::$seq),
                $staffStart, $staffEnd, $start, $end,
                $o['capacity'] ?? null,
                $o['note'] ?? null,
                !empty($o['photograph']) ? 't' : 'f',
                $o['photograph_note'] ?? null,
                !empty($o['not_main_organizer']) ? 't' : 'f',
                self::statusId('draft'),
            ]
        );

        self::setStatus($actionId, 'draft');
        if (($o['status'] ?? 'draft') !== 'draft') {
            self::setStatus($actionId, $o['status']);
        }

        foreach ($o['organizers'] ?? [] as $userId) {
            self::pdo()->prepare('INSERT INTO action_organizers (action_id, user_id) VALUES (?, ?)')->execute([$actionId, $userId]);
        }
        foreach ($o['substitutes'] ?? [] as $userId) {
            self::pdo()->prepare('INSERT INTO action_substitutes (action_id, user_id) VALUES (?, ?)')->execute([$actionId, $userId]);
        }
        foreach ($o['staff'] ?? [] as $personId) {
            self::pdo()->prepare('INSERT INTO action_staff (action_id, person_id) VALUES (?, ?)')->execute([$actionId, $personId]);
        }

        $links = [
            'types'      => ['action_action_types', 'action_type_id', 'action_types'],
            'topics'     => ['action_action_topics', 'action_topic_id', 'action_topics'],
            'formats'    => ['action_action_formats', 'action_format_id', 'action_formats'],
            'categories' => ['action_target_visitor_categories', 'visitor_category_id', 'visitor_categories'],
        ];
        foreach ($links as $key => [$table, $column, $dictionary]) {
            foreach ($o[$key] ?? [] as $name) {
                self::pdo()->prepare("INSERT INTO {$table} (action_id, {$column}) VALUES (?, ?)")
                    ->execute([$actionId, self::dict($dictionary, $name)]);
            }
        }

        return $actionId;
    }

    /** Moves the action to a status the way the app does it — a status-history row (a DB trigger syncs actual_status). */
    public static function setStatus(int $actionId, string $code): void
    {
        self::pdo()->prepare('INSERT INTO action_status_history (action_id, action_status_id) VALUES (?, ?)')
            ->execute([$actionId, self::statusId($code)]);
    }

    /** Room request (queue entry). $createdAt fixes the queue order explicitly instead of relying on insert timing. */
    public static function request(int $actionId, int $placeId, ?string $createdAt = null, ?string $note = null): void
    {
        self::pdo()->prepare(
            'INSERT INTO action_places_desire (action_id, place_id, note, created_at) VALUES (?, ?, ?, COALESCE(?::timestamp, CURRENT_TIMESTAMP))'
        )->execute([$actionId, $placeId, $note, $createdAt]);
    }

    /** Confirmed room assignment — like the app, a confirmed room is a request (desire) plus its confirmation. */
    public static function confirm(int $actionId, int $placeId, ?string $note = null): void
    {
        self::pdo()->prepare(
            'INSERT INTO action_places_desire (action_id, place_id, note) VALUES (?, ?, ?) ON CONFLICT (action_id, place_id) DO NOTHING'
        )->execute([$actionId, $placeId, $note]);
        self::pdo()->prepare('INSERT INTO action_places (action_id, place_id, note) VALUES (?, ?, ?)')
            ->execute([$actionId, $placeId, $note]);
    }

    public static function attendance(int $actionId, string $visitorType, string $visitorCategory, int $count): void
    {
        self::pdo()->prepare(
            'INSERT INTO action_attendance (action_id, visitor_type_id, visitor_category_id, visitor_count) VALUES (?, ?, ?, ?)'
        )->execute([$actionId, self::dict('visitor_types', $visitorType), self::dict('visitor_categories', $visitorCategory), $count]);
    }

    public static function income(int $actionId, string $type, string $amount, string $currency = 'CZK'): void
    {
        self::pdo()->prepare(
            'INSERT INTO action_incomes (action_id, income_type_id, amount, currency_id) VALUES (?, ?, ?, ?)'
        )->execute([$actionId, self::dict('income_types', $type), $amount, self::currencyId($currency)]);
    }

    public static function cost(int $actionId, string $type, string $object, string $amount, string $currency = 'CZK'): void
    {
        self::pdo()->prepare(
            'INSERT INTO action_costs (action_id, cost_type_id, cost_object_id, amount, currency_id) VALUES (?, ?, ?, ?, ?)'
        )->execute([$actionId, self::dict('cost_types', $type), self::dict('cost_objects', $object), $amount, self::currencyId($currency)]);
    }
}
