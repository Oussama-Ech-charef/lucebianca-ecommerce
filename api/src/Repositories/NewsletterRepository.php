<?php

namespace App\Repositories;

use PDO;

/**
 * NewsletterRepository — newsletter subscriber data access.
 */
final class NewsletterRepository extends Repository
{
    /**
     * Subscribe an email to newsletter.
     *
     * @return bool True if subscribed, false if already subscribed
     */
    public function subscribe(string $email): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO newsletter_subscribers (email, is_active, subscribed_at)
                VALUES (:email, 1, NOW())
            ");
            $stmt->bindValue(':email', $email, PDO::PARAM_STR);
            $stmt->execute();
            return true;
        } catch (\PDOException $e) {
            // Duplicate email - reactivate if inactive
            if ($e->getCode() === '23000') {
                $stmt = $this->db->prepare("
                    UPDATE newsletter_subscribers
                    SET is_active = 1, subscribed_at = NOW(), unsubscribed_at = NULL
                    WHERE email = :email AND is_active = 0
                ");
                $stmt->bindValue(':email', $email, PDO::PARAM_STR);
                $stmt->execute();
                return $stmt->rowCount() > 0;
            }
            throw $e;
        }
    }

    /**
     * Unsubscribe an email. Restores nothing else; the audit counter records
     * every effective unsubscribe (repeated calls on an inactive address are
     * no-ops and do not increment).
     */
    public function unsubscribe(string $email): bool
    {
        $stmt = $this->db->prepare("
            UPDATE newsletter_subscribers
            SET is_active = 0, unsubscribed_at = NOW(), unsubscribe_count = unsubscribe_count + 1
            WHERE email = :email AND is_active = 1
        ");
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Check if email is subscribed.
     */
    public function isSubscribed(string $email): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM newsletter_subscribers
            WHERE email = :email AND is_active = 1
        ");
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Get all active subscribers.
     */
    public function getActiveSubscribers(): array
    {
        $stmt = $this->db->prepare("
            SELECT email, subscribed_at
            FROM newsletter_subscribers
            WHERE is_active = 1
            ORDER BY subscribed_at DESC
        ");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
