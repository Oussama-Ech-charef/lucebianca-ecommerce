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

    /**
     * Get all messages with pagination and optional read/unread filter.
     */
    public function paginate(int $page, int $perPage, ?int $isRead = null): array
    {
        $offset = ($page - 1) * $perPage;

        $whereClause = '';
        if ($isRead !== null) {
            $whereClause = 'WHERE is_read = :is_read';
        }

        // Count total
        $countSql = "SELECT COUNT(*) FROM contact_messages $whereClause";
        $stmt = $this->pdo()->prepare($countSql);
        if ($isRead !== null) {
            $stmt->bindValue(':is_read', $isRead, \PDO::PARAM_INT);
        }
        $stmt->execute();
        $total = (int) $stmt->fetchColumn();

        // Fetch paginated
        $sql = "
            SELECT id, name, email, message, is_read, created_at
            FROM contact_messages
            $whereClause
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $this->pdo()->prepare($sql);
        if ($isRead !== null) {
            $stmt->bindValue(':is_read', $isRead, \PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
            'total' => $total,
        ];
    }

    /**
     * Mark a message as read.
     */
    public function markAsRead(int $id): void
    {
        $stmt = $this->pdo()->prepare("
            UPDATE contact_messages
            SET is_read = 1
            WHERE id = :id
        ");
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Delete a message.
     */
    public function delete(int $id): void
    {
        $stmt = $this->pdo()->prepare("DELETE FROM contact_messages WHERE id = :id");
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
    }
}