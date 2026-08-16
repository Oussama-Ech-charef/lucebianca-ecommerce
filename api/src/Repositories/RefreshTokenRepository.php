<?php

namespace App\Repositories;

/**
 * RefreshTokenRepository — persistence for refresh_tokens.
 *
 * Only the SHA-256 hash of a refresh token is ever stored (the raw
 * token is returned to the client once at issuance and never written
 * to the database). All queries are PDO prepared statements.
 *
 * The findActive()/revoke() methods are what logout and rotation will
 * call in a future phase — they exist now so the design is complete.
 */
final class RefreshTokenRepository extends Repository
{
    /**
     * Stores a new refresh token record.
     *
     * @param int    $userId    Owner account id (FK -> users.id).
     * @param string $tokenHash SHA-256 hex hash of the raw token.
     * @param string $expiresAt DATETIME (app timezone) when the token expires.
     *
     * @return int Auto-increment id of the new record.
     */
    public function create(int $userId, string $tokenHash, string $expiresAt): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO refresh_tokens (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, :expires_at)'
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
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
            'SELECT * FROM refresh_tokens
             WHERE token_hash = :token_hash
               AND revoked_at IS NULL
               AND expires_at > NOW()
             LIMIT 1'
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
            'UPDATE refresh_tokens SET revoked_at = NOW() WHERE token_hash = :token_hash'
        );
        $stmt->bindValue(':token_hash', $tokenHash);
        $stmt->execute();
    }
}