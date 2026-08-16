<?php

namespace App\Repositories;

use App\Models\User;

/**
 * UserRepository — database access for customer accounts.
 *
 * Registration / login logic (hashing, verification) lives in
 * App\Services\AuthService — this class never touches passwords beyond
 * storing/reading the hash.
 */
final class UserRepository extends Repository
{
    /**
     * Finds a user by primary key (used after insert to load the fresh row).
     */
    public function findById(int $id): ?User
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row === false ? null : User::fromRow($row);
    }

    /**
     * Finds a user by email (used by login + Google OAuth matching).
     */
    public function findByEmail(string $email): ?User
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->bindValue(':email', $email);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row === false ? null : User::fromRow($row);
    }

    /**
     * Creates a user. Returns the new record's auto-increment id.
     *
     * @param string $name         Display name.
     * @param string $email        Unique email address.
     * @param string $passwordHash Already hashed with password_hash().
     * @param string|null $phone   Optional phone number.
     */
    public function create(string $name, string $email, string $passwordHash, ?string $phone = null): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO users (name, email, password_hash, phone) VALUES (:name, :email, :hash, :phone)'
        );
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':email', $email);
        $stmt->bindValue(':hash', $passwordHash);
        $stmt->bindValue(':phone', $phone);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }
}