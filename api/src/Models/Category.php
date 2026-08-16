<?php

namespace App\Models;

/**
 * Category — data holder for the categories table.
 */
final class Category
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public string $createdAt
    ) {
    }

    /**
     * Builds a Category from a database row.
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['slug'],
            (string) $row['created_at']
        );
    }

    /**
     * Serializes the category for JSON responses.
     */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'slug'       => $this->slug,
            'created_at' => $this->createdAt,
        ];
    }
}