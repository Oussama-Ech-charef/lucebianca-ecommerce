<?php

namespace App\Repositories;

use App\Models\Admin;

/**
 * AdminRepository — database access for admin panel accounts.
 *
 * Login logic (hashing, verification) lives in App\Services\AdminAuthService —
 * this class never touches passwords beyond storing/reading the hash.
 */
final class AdminRepository extends Repository
{
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
     * Creates an admin. Used by the CLI seed script only — there is NO
     * public HTTP route that can create an admin.
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
}