<?php

namespace App\Repositories;

use App\Models\Coupon;

/**
 * CouponRepository — persistence for discount codes.
 *
 * Validation is intentionally narrow and matches the schema: a coupon is
 * usable when it exists, is_active = 1, and expires_at is either NULL or in
 * the future. No usage counters, customer limits or minimum-spend rules are
 * inferred because the schema has no columns for them.
 */
final class CouponRepository extends Repository
{
    /**
     * All coupons for the admin list (including expired/inactive rows).
     *
     * @return array<int, Coupon>
     */
    public function all(): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM coupons ORDER BY is_active DESC, expires_at IS NULL DESC, expires_at ASC, code ASC'
        );
        $stmt->execute();

        return array_map(
            static fn (array $row): Coupon => Coupon::fromRow($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Finds a coupon by primary key.
     */
    public function findById(int $id): ?Coupon
    {
        $stmt = $this->db->prepare('SELECT * FROM coupons WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row === false ? null : Coupon::fromRow($row);
    }

    /**
     * Case-insensitive lookup by code (utf8mb4_unicode_ci collation).
     */
    public function findByCode(string $code): ?Coupon
    {
        $stmt = $this->db->prepare('SELECT * FROM coupons WHERE code = :code LIMIT 1');
        $stmt->bindValue(':code', $code);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row === false ? null : Coupon::fromRow($row);
    }

    /**
     * Finds a coupon that is currently usable (active + not expired).
     */
    public function findValidByCode(string $code): ?Coupon
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM coupons
              WHERE code = :code
                AND is_active = 1
                AND (expires_at IS NULL OR expires_at > NOW())
              LIMIT 1'
        );
        $stmt->bindValue(':code', $code);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row === false ? null : Coupon::fromRow($row);
    }

    /**
     * Same as findValidByCode(), locked for the current transaction.
     *
     * Order placement uses this so an admin edit/deactivation that commits
     * between the checkout preview and the order insert cannot slip through:
     * the order re-reads the row under SELECT ... FOR UPDATE and applies the
     * pricing valid at commit time.
     */
    public function findValidByCodeForUpdate(string $code): ?Coupon
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM coupons
              WHERE code = :code
                AND is_active = 1
                AND (expires_at IS NULL OR expires_at > NOW())
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->bindValue(':code', $code);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row === false ? null : Coupon::fromRow($row);
    }

    /**
     * Whether a code is already taken (optionally excluding one id).
     */
    public function codeExists(string $code, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM coupons WHERE code = :code';
        if ($excludeId !== null) {
            $sql .= ' AND id <> :id';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':code', $code);
        if ($excludeId !== null) {
            $stmt->bindValue(':id', $excludeId, \PDO::PARAM_INT);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Creates a coupon and returns its id.
     *
     * @param array{code: string, discount_type: string, discount_value: string,
     *              expires_at: string|null, is_active: int} $data
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO coupons (code, discount_type, discount_value, expires_at, is_active)
             VALUES (:code, :discount_type, :discount_value, :expires_at, :is_active)'
        );
        $stmt->bindValue(':code', $data['code']);
        $stmt->bindValue(':discount_type', $data['discount_type']);
        $stmt->bindValue(':discount_value', $data['discount_value']);
        $stmt->bindValue(
            ':expires_at',
            $data['expires_at'],
            $data['expires_at'] === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR
        );
        $stmt->bindValue(':is_active', (int) $data['is_active'], \PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates whitelisted coupon columns.
     *
     * @param array<string, mixed> $fields Subset of: code, discount_type,
     *                                     discount_value, expires_at, is_active.
     */
    public function update(int $id, array $fields): void
    {
        $allowed = [
            'code'           => true,
            'discount_type'  => true,
            'discount_value' => true,
            'expires_at'     => true,
            'is_active'      => true,
        ];

        $sets = [];
        foreach ($fields as $key => $value) {
            if (isset($allowed[$key])) {
                $sets[] = "{$key} = :{$key}";
            }
        }
        if ($sets === []) {
            return;
        }

        $stmt = $this->db->prepare('UPDATE coupons SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        foreach ($fields as $key => $value) {
            if (!isset($allowed[$key])) {
                continue;
            }
            if ($key === 'expires_at' && $value === null) {
                $stmt->bindValue(':expires_at', null, \PDO::PARAM_NULL);
            } elseif ($key === 'is_active') {
                $stmt->bindValue(':is_active', (int) $value, \PDO::PARAM_INT);
            } else {
                $stmt->bindValue(":{$key}", (string) $value);
            }
        }
        $stmt->execute();
    }

    /**
     * Deletes a coupon. Orders keep coupon_code + discount_amount snapshots;
     * their coupon_id becomes NULL via ON DELETE SET NULL.
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM coupons WHERE id = :id');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
    }
}
