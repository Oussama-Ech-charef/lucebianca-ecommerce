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
     * Finds a user by their Google account id (Google OAuth re-login).
     */
    public function findByGoogleId(string $googleId): ?User
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE google_id = :google_id LIMIT 1');
        $stmt->bindValue(':google_id', $googleId);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row === false ? null : User::fromRow($row);
    }

    /**
     * Finds a user by their pending email-verification token.
     */
    public function findByEmailVerificationToken(string $token): ?User
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM users WHERE email_verification_token = :token LIMIT 1'
        );
        $stmt->bindValue(':token', $token);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row === false ? null : User::fromRow($row);
    }

    /**
     * Creates a user. Returns the new record's auto-increment id.
     *
     * @param string      $name         Display name.
     * @param string      $email        Unique email address.
     * @param string      $passwordHash Already hashed with password_hash().
     * @param string|null $phone        Optional phone number.
     * @param string|null $googleId     Google account id (null = email/password).
     * @param bool        $emailVerified True when the email is already proven
     *                                   (Google accounts — Google verifies the
     *                                   address before we trust it).
     */
    public function create(
        string $name,
        string $email,
        string $passwordHash,
        ?string $phone = null,
        ?string $googleId = null,
        bool $emailVerified = false
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO users (name, email, password_hash, phone, google_id, email_verified)
             VALUES (:name, :email, :hash, :phone, :google_id, :email_verified)'
        );
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':email', $email);
        $stmt->bindValue(':hash', $passwordHash);
        $stmt->bindValue(':phone', $phone);
        $stmt->bindValue(':google_id', $googleId);
        $stmt->bindValue(':email_verified', $emailVerified ? 1 : 0, \PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    /**
     * Stores a fresh verification token + expiry for a user (single-use,
     * overwrites any previously pending token).
     */
    public function setEmailVerification(int $userId, string $token, string $expiresAt): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users
                SET email_verification_token = :token,
                    email_verification_expires_at = :expires_at
              WHERE id = :id'
        );
        $stmt->bindValue(':token', $token);
        $stmt->bindValue(':expires_at', $expiresAt);
        $stmt->bindValue(':id', $userId, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Marks a user's email verified and clears their verification token
     * (single-use: the same link can never be replayed).
     */
    public function markEmailVerified(int $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users
                SET email_verified = 1,
                    email_verification_token = NULL,
                    email_verification_expires_at = NULL
              WHERE id = :id'
        );
        $stmt->bindValue(':id', $userId, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Attaches a Google account id to an existing user (create-or-link by
     * email: a user who first registered with a password then signs in with
     * Google keeps their account and gains Google login).
     */
    public function linkGoogle(int $userId, string $googleId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET google_id = :google_id WHERE id = :id'
        );
        $stmt->bindValue(':google_id', $googleId);
        $stmt->bindValue(':id', $userId, \PDO::PARAM_INT);
        $stmt->execute();
    }
}