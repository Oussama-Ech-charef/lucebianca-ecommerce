<?php

namespace App\Repositories;

use App\Core\ConflictException;
use App\Models\Admin;

/**
 * AdminRepository — database access for admin panel accounts.
 *
 * Login logic (hashing, verification) lives in App\Services\AdminAuthService —
 * this class never touches passwords beyond storing/reading the hash.
 *
 * Roles are not managed here: every admin account is an equal operator and
 * is created with the schema's default role. There is no deactivation flag —
 * removal is deletion (guarded against deleting yourself or the last admin).
 */
final class AdminRepository extends Repository
{
    /**
     * All admin accounts for the settings screen.
     *
     * @return array<int, Admin>
     */
    public function all(): array
    {
        $stmt = $this->db->prepare('SELECT * FROM admins ORDER BY name ASC, id ASC');
        $stmt->execute();

        return array_map(
            static fn (array $row): Admin => Admin::fromRow($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Finds an admin by primary key.
     */
    public function findById(int $id): ?Admin
    {
        $stmt = $this->db->prepare('SELECT * FROM admins WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row === false ? null : Admin::fromRow($row);
    }

    /**
     * Finds an admin by email (used by login).
     */
    public function findByEmail(string $email): ?Admin
    {
        $stmt = $this->db->prepare('SELECT * FROM admins WHERE email = :email LIMIT 1');
        $stmt->bindValue(':email', $email);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row === false ? null : Admin::fromRow($row);
    }

    /**
     * Creates an admin. Used by the CLI seed script and by authenticated
     * admins through POST /api/admin/accounts; there is NO public route.
     *
     * @param string $name         Display name.
     * @param string $email        Unique email address.
     * @param string $passwordHash Already hashed with password_hash().
     * @param string $role         Role label (default 'admin').
     *
     * @return int Auto-increment id of the new record.
     */
    public function create(string $name, string $email, string $passwordHash, string $role = 'admin'): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO admins (name, email, password_hash, role) VALUES (:name, :email, :hash, :role)'
        );
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':email', $email);
        $stmt->bindValue(':hash', $passwordHash);
        $stmt->bindValue(':role', $role);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates whitelisted admin columns.
     *
     * A password change also revokes every admin refresh-token session in the
     * same transaction. Access JWTs carry the session id, so this invalidates
     * both old refresh tokens and old access tokens immediately.
     *
     * @param array<string, mixed> $fields Subset of: name, email, password_hash.
     */
    public function update(int $id, array $fields): void
    {
        $allowed = ['name' => true, 'email' => true, 'password_hash' => true];

        $sets = [];
        foreach ($fields as $key => $value) {
            if (isset($allowed[$key])) {
                $sets[] = "{$key} = :{$key}";
            }
        }
        if ($sets === []) {
            return;
        }

        $passwordChanged = isset($fields['password_hash']);
        $startedTransaction = false;
        if ($passwordChanged) {
            $this->db->beginTransaction();
            $startedTransaction = true;
        }

        try {
            $stmt = $this->db->prepare('UPDATE admins SET ' . implode(', ', $sets) . ' WHERE id = :id');
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
            foreach ($fields as $key => $value) {
                if (isset($allowed[$key])) {
                    $stmt->bindValue(":{$key}", (string) $value);
                }
            }
            $stmt->execute();

            if ($passwordChanged) {
                $revoke = $this->db->prepare(
                    'UPDATE admin_refresh_tokens
                        SET revoked_at = NOW()
                      WHERE admin_id = :id
                        AND revoked_at IS NULL'
                );
                $revoke->bindValue(':id', $id, \PDO::PARAM_INT);
                $revoke->execute();
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Whether an email is already used by an admin (case-insensitive).
     */
    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM admins WHERE email = :email';
        if ($excludeId !== null) {
            $sql .= ' AND id <> :id';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':email', $email);
        if ($excludeId !== null) {
            $stmt->bindValue(':id', $excludeId, \PDO::PARAM_INT);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Number of admin accounts.
     */
    public function count(): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM admins');
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Deletes an admin while holding a table-wide row lock, so two
     * concurrent deletions can never remove every admin account.
     *
     * Guards (checked under the lock):
     * - the requesting admin cannot delete their own account;
     * - the last remaining admin account can never be deleted.
     *
     * @throws ConflictException When the target is missing, is the actor, or
     *                           would leave zero admins.
     */
    public function deleteGuarded(int $id, int $actorId): void
    {
        $pdo = $this->db;
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->query('SELECT id FROM admins ORDER BY id FOR UPDATE');
            $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
            $stmt->closeCursor();

            if (!in_array($id, $ids, true)) {
                $pdo->rollBack();
                throw new ConflictException('Admin account not found.');
            }
            if ($id === $actorId) {
                $pdo->rollBack();
                throw new ConflictException('You cannot delete your own admin account.');
            }
            if (count($ids) <= 1) {
                $pdo->rollBack();
                throw new ConflictException('The last admin account cannot be deleted.');
            }

            $delete = $pdo->prepare('DELETE FROM admins WHERE id = :id');
            $delete->bindValue(':id', $id, \PDO::PARAM_INT);
            $delete->execute();

            $pdo->commit();
        } catch (ConflictException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Deletes an admin. Callers should prefer deleteGuarded() for accounts
     * removed through the HTTP API. Refresh tokens cascade via the schema FK.
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM admins WHERE id = :id');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
    }
}