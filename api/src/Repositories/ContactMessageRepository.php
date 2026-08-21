<?php

namespace App\Repositories;

/**
 * ContactMessageRepository — database access for /contact form submissions.
 *
 * Messages land in contact_messages with is_read = 0 (the read/unread flag
 * powers /admin/messages). Only prepared statements with bound values are
 * used — a message body is never concatenated into SQL (security 3.1).
 */
final class ContactMessageRepository extends Repository
{
    /**
     * Stores a contact message.
     *
     * @return int Auto-increment id of the new record.
     */
    public function create(string $name, string $email, string $message): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO contact_messages (name, email, message, is_read)
             VALUES (:name, :email, :message, 0)'
        );
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':email', $email);
        $stmt->bindValue(':message', $message);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }
}