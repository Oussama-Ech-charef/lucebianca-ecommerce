<?php

namespace App\Repositories;

/**
 * RefreshTokenRepository — persistence for refresh_tokens.
 *
 * Only the SHA-256 hash of a refresh token is ever stored (the raw
 * token is returned to the client once at issuance and never written
 * to the database). All queries are PDO prepared statements.
 *
 * The table and identity column are overridable so the same code path
 * serves admin refresh tokens too (see AdminRefreshTokenRepository) —
 * the identifiers are internal class constants, never user input.
 */
class RefreshTokenRepository extends Repository
{
    /** Table holding this principal's refresh tokens. */
    protected string $table = 'refresh_tokens';

    /** Column holding the principal id (FK -> principal table). */
    protected string $idColumn = 'user_id';

    /**
     * Stores a new refresh token record.
     *
     * @param int    $principalId Owner account id (FK -> the principal table).
     * @param string $tokenHash   SHA-256 hex hash of the raw token.
     * @param string $expiresAt   DATETIME (app timezone) when the token expires.
     *
     * @return int Auto-increment id of the new record.
     */
    public function create(int $principalId, string $tokenHash, string $expiresAt): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO {$this->table} ({$this->idColumn}, token_hash, expires_at)
             VALUES (:principal_id, :token_hash, :expires_at)"
        );
        $stmt->bindValue(':principal_id', $principalId, \PDO::PARAM_INT);
        $stmt->bindValue(':token_hash', $tokenHash);
        $stmt->bindValue(':expires_at', $expiresAt);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    /**
     * Finds a still-valid (non-expired, non-revoked) token by its hash.
     *
     * @return array|null The matching row, or null when unknown/invalid.
     */
    public function findActive(string $tokenHash): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table}
             WHERE token_hash = :token_hash
               AND revoked_at IS NULL
               AND expires_at > NOW()
             LIMIT 1"
        );
        $stmt->bindValue(':token_hash', $tokenHash);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Marks a refresh token as revoked (used by logout / rotation).
     */
    public function revoke(string $tokenHash): void
    {
        $stmt = $this->db->prepare(
            "UPDATE {$this->table} SET revoked_at = NOW() WHERE token_hash = :token_hash"
        );
        $stmt->bindValue(':token_hash', $tokenHash);
        $stmt->execute();
    }
}