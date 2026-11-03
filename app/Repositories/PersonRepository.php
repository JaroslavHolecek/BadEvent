<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class PersonRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM persons WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Returns all non-deleted persons with their active contact count, ordered by name. */
    public function listAll(): array
    {
        $sql = <<<SQL
            SELECT p.id, p.full_name, p.short_name, p.note, p.created_at, p.deactivated_at,
                   COUNT(c.id) AS contact_count
            FROM persons p
            LEFT JOIN contacts c ON c.person_id = p.id AND c.deactivated_at IS NULL
            WHERE p.deleted_at IS NULL
            GROUP BY p.id
            ORDER BY p.full_name ASC
        SQL;
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll();
    }

    public function create(string $fullName, ?string $shortName, ?string $note): int
    {
        $sql = <<<SQL
            INSERT INTO persons (full_name, short_name, note)
            VALUES (:full_name, :short_name, :note)
            RETURNING id
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':full_name' => $fullName, ':short_name' => $shortName, ':note' => $note]);
        return (int) $stmt->fetchColumn();
    }

    public function update(int $id, string $fullName, ?string $shortName, ?string $note): void
    {
        $sql = <<<SQL
            UPDATE persons
            SET full_name = :full_name, short_name = :short_name, note = :note
            WHERE id = :id
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id, ':full_name' => $fullName, ':short_name' => $shortName, ':note' => $note]);
    }

    public function deactivate(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE persons SET deactivated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function activate(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE persons SET deactivated_at = NULL WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /** Soft-deletes the person (anonymizes name, drops contacts) via the DB-side soft_delete_person function. */
    public function softDelete(int $id): void
    {
        $stmt = $this->pdo->prepare('SELECT soft_delete_person(:id)');
        $stmt->execute([':id' => $id]);
    }

    /** Returns the owning person_id of a contact, or null if it doesn't exist. Used to authorize self-service edits. */
    public function contactPersonId(int $contactId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT person_id FROM contacts WHERE id = :id');
        $stmt->execute([':id' => $contactId]);
        $personId = $stmt->fetchColumn();
        return $personId !== false ? (int) $personId : null;
    }

    public function getContacts(int $personId): array
    {
        $sql = <<<SQL
            SELECT c.id, c.value, c.note, c.deactivated_at,
                   ct.id AS contact_type_id, ct.name AS contact_type_name, ct.abbreviation AS contact_type_abbreviation
            FROM contacts c
            JOIN contact_types ct ON ct.id = c.contact_type_id
            WHERE c.person_id = :person_id
            ORDER BY ct.name ASC, c.value ASC
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':person_id' => $personId]);
        return $stmt->fetchAll();
    }

    public function listActiveContactTypes(): array
    {
        $stmt = $this->pdo->query('SELECT id, name, abbreviation FROM contact_types WHERE deactivated_at IS NULL ORDER BY name ASC');
        return $stmt->fetchAll();
    }

    public function addContact(int $personId, int $contactTypeId, string $value, ?string $note): int
    {
        $sql = <<<SQL
            INSERT INTO contacts (person_id, contact_type_id, value, note)
            VALUES (:person_id, :contact_type_id, :value, :note)
            RETURNING id
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':person_id'       => $personId,
            ':contact_type_id' => $contactTypeId,
            ':value'           => $value,
            ':note'            => $note,
        ]);
        return (int) $stmt->fetchColumn();
    }

    public function deactivateContact(int $contactId): void
    {
        $stmt = $this->pdo->prepare('UPDATE contacts SET deactivated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([':id' => $contactId]);
    }

    public function activateContact(int $contactId): void
    {
        $stmt = $this->pdo->prepare('UPDATE contacts SET deactivated_at = NULL WHERE id = :id');
        $stmt->execute([':id' => $contactId]);
    }

    public function findContactById(int $contactId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, person_id, contact_type_id, value, note FROM contacts WHERE id = :id');
        $stmt->execute([':id' => $contactId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function updateContact(int $contactId, int $contactTypeId, string $value, ?string $note): void
    {
        $sql = <<<SQL
            UPDATE contacts
            SET contact_type_id = :contact_type_id, value = :value, note = :note
            WHERE id = :id
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id'              => $contactId,
            ':contact_type_id' => $contactTypeId,
            ':value'           => $value,
            ':note'            => $note,
        ]);
    }

    public function deleteContact(int $contactId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM contacts WHERE id = :id');
        $stmt->execute([':id' => $contactId]);
    }
}
