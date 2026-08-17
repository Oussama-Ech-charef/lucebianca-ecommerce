<?php

namespace App\Models;

/**
 * Admin — data holder for admin panel accounts (admins table).
 *
 * Admins are fully separate from customers (users table) per spec
 * section 4. password_hash is only read internally by AdminAuthService;
 * it is never included in JSON responses.
 */
final class Admin
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $passwordHash,
        public string $role,
        public string $createdAt
    ) {
    }

    /**
     * Builds an Admin from a database row.
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['email'],
            (string) $row['password_hash'],
            (string) ($row['role'] ?? 'admin'),
            (string) $row['created_at']
        );
    }

    /**
     * Serializes the admin safely — the password hash is never exposed.
     */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'email'      => $this->email,
            'role'       => $this->role,
            'created_at' => $this->createdAt,
        ];
    }
}