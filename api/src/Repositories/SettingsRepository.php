<?php

namespace App\Repositories;

use PDO;

/**
 * SettingsRepository — key-value settings storage.
 *
 * Settings stored in the `settings` table as key-value pairs.
 * Used for store configuration that admins can modify through the UI.
 */
final class SettingsRepository extends Repository
{
    /**
     * Get a setting value by key.
     */
    public function get(string $key): ?string
    {
        $stmt = $this->db->prepare("
            SELECT setting_value AS value FROM settings WHERE setting_key = :key
        ");
        $stmt->bindValue(':key', $key, PDO::PARAM_STR);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result !== false ? $result['value'] : null;
    }

    /**
     * Get multiple settings by keys.
     *
     * @param string[] $keys
     * @return array<string, string> Key-value pairs (missing keys omitted)
     */
    public function getMultiple(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->db->prepare("
            SELECT setting_key AS `key`, setting_value AS value FROM settings WHERE setting_key IN ($placeholders)
        ");

        foreach ($keys as $index => $key) {
            $stmt->bindValue($index + 1, $key, PDO::PARAM_STR);
        }

        $stmt->execute();

        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[$row['key']] = $row['value'];
        }

        return $result;
    }

    /**
     * Get all settings.
     *
     * @return array<string, string>
     */
    public function getAll(): array
    {
        $stmt = $this->db->prepare("SELECT setting_key AS `key`, setting_value AS value FROM settings");
        $stmt->execute();

        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[$row['key']] = $row['value'];
        }

        return $result;
    }

    /**
     * Set a setting value (insert or update).
     */
    public function set(string $key, string $value): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO settings (setting_key, setting_value)
            VALUES (:key, :value)
            ON DUPLICATE KEY UPDATE setting_value = :value_dup
        ");
        $stmt->bindValue(':key', $key, PDO::PARAM_STR);
        $stmt->bindValue(':value', $value, PDO::PARAM_STR);
        $stmt->bindValue(':value_dup', $value, PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * Set multiple settings at once.
     *
     * @param array<string, string> $settings
     */
    public function setMultiple(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $this->set($key, $value);
        }
    }

    /**
     * Delete a setting.
     */
    public function delete(string $key): void
    {
        $stmt = $this->db->prepare("DELETE FROM settings WHERE setting_key = :key");
        $stmt->bindValue(':key', $key, PDO::PARAM_STR);
        $stmt->execute();
    }
}
