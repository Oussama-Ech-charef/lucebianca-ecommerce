<?php

namespace App\Models;

/**
 * User — data holder for customer accounts (users table).
 *
 * password_hash is only read internally (AuthService); it is never
 * included in JSON responses.
 */
final class User
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $passwordHash,
        public ?string $phone,
        public string $createdAt
    ) {
    }

    /**
     * Builds a User from a database row.
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['email'],
            (string) $row['password_hash'],
            $row['phone'] ?? null,
            (string) $row['created_at']
        );
    }

    /**
     * Serializes the user safely — the password hash is never exposed.
     */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'email'      => $this->email,
            'phone'      => $this->phone,
            'created_at' => $this->createdAt,
        ];
    }
}