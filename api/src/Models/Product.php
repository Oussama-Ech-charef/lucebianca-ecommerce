<?php

namespace App\Models;

/**
 * Product — data holder for the products table.
 *
 * Anemic model: holds data, no database logic (Repositories own the SQL).
 */
final class Product
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public ?string $description,
        public string $basePrice,
        public ?int $categoryId,
        public int $isActive,
        public ?string $metaTitle,
        public ?string $metaDescription,
        public string $createdAt,
        public string $updatedAt
    ) {
    }

    /**
     * Builds a Product from a database row.
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['slug'],
            $row['description'] ?? null,
            (string) $row['base_price'],
            isset($row['category_id']) ? (int) $row['category_id'] : null,
            (int) $row['is_active'],
            $row['meta_title'] ?? null,
            $row['meta_description'] ?? null,
            (string) $row['created_at'],
            (string) $row['updated_at']
        );
    }

    /**
     * Serializes the product for JSON responses.
     */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'slug'             => $this->slug,
            'description'      => $this->description,
            'base_price'       => $this->basePrice,
            'category_id'      => $this->categoryId,
            'is_active'        => $this->isActive,
            'meta_title'       => $this->metaTitle,
            'meta_description' => $this->metaDescription,
            'created_at'       => $this->createdAt,
            'updated_at'       => $this->updatedAt,
        ];
    }
}