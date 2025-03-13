<?php

namespace Ody\SwooleRedis\Storage;

use Ody\SwooleRedis\MemoryManager;

/**
 * Storage for hash values
 */
class HashStorage implements StorageInterface
{
    private \Swoole\Table $table;

    public function __construct(int $tableSize = 0)
    {
        // Use MemoryManager to determine table size
        $tableSize = MemoryManager::getTableSize('hash', $tableSize > 0 ? $tableSize : null);

        // Initialize table for hash storage
        $this->table = new \Swoole\Table($tableSize);
        $this->table->column('key', \Swoole\Table::TYPE_STRING, 128);    // Parent key name
        $this->table->column('field', \Swoole\Table::TYPE_STRING, 128);  // Field name
        $this->table->column('value', \Swoole\Table::TYPE_STRING, 1024); // Field value (1KB max)
        $this->table->create();
    }

    /**
     * Get all hash keys in the storage
     *
     * @return array Array of unique hash keys
     */
    public function getAllKeys(): array
    {
        $keys = [];
        $processedKeys = [];

        foreach ($this->table as $hashId => $row) {
            $key = $row['key'];

            // Only add each key once
            if (!isset($processedKeys[$key])) {
                $keys[] = $key;
                $processedKeys[$key] = true;
            }
        }

        return $keys;
    }

    /**
     * Set a field in a hash
     *
     * @param string $key The hash key
     * @param string $field The field name
     * @param string $value The field value
     * @return bool True if field is new, false if it was updated
     */
    public function hSet(string $key, string $field, string $value): bool
    {
        // Create a unique ID for this hash field
        $hashId = $this->createHashId($key, $field);

        $isNew = !$this->table->exist($hashId);

        $this->table->set($hashId, [
            'key' => $key,
            'field' => $field,
            'value' => $value
        ]);

        return $isNew;
    }

    /**
     * Get a field from a hash
     *
     * @param string $key The hash key
     * @param string $field The field name
     * @return string|null The field value or null if not found
     */
    public function hGet(string $key, string $field): ?string
    {
        $hashId = $this->createHashId($key, $field);

        if (!$this->table->exist($hashId)) {
            return null;
        }

        $row = $this->table->get($hashId);
        return $row['value'];
    }

    /**
     * Delete a field from a hash
     *
     * @param string $key The hash key
     * @param string $field The field name
     * @return bool True if field was deleted, false otherwise
     */
    public function hDel(string $key, string $field): bool
    {
        $hashId = $this->createHashId($key, $field);

        if (!$this->table->exist($hashId)) {
            return false;
        }

        return $this->table->del($hashId);
    }

    /**
     * Get all fields in a hash
     *
     * @param string $key The hash key
     * @return array An array of field names
     */
    public function hKeys(string $key): array
    {
        $fields = [];

        foreach ($this->table as $hashId => $row) {
            if ($row['key'] === $key) {
                $fields[] = $row['field'];
            }
        }

        return $fields;
    }

    /**
     * Get all values in a hash
     *
     * @param string $key The hash key
     * @return array An array of field values
     */
    public function hVals(string $key): array
    {
        $values = [];

        foreach ($this->table as $hashId => $row) {
            if ($row['key'] === $key) {
                $values[] = $row['value'];
            }
        }

        return $values;
    }

    /**
     * Get all fields and values in a hash
     *
     * @param string $key The hash key
     * @return array An associative array of field => value pairs
     */
    public function hGetAll(string $key): array
    {
        $result = [];

        foreach ($this->table as $hashId => $row) {
            if ($row['key'] === $key) {
                $result[$row['field']] = $row['value'];
            }
        }

        return $result;
    }

    /**
     * Check if a hash exists
     *
     * @param string $key The hash key
     * @return bool True if the hash exists, false otherwise
     */
    public function exists(string $key): bool
    {
        foreach ($this->table as $hashId => $row) {
            if ($row['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Delete a hash
     *
     * @param string $key The hash key
     * @return bool True if hash was deleted, false otherwise
     */
    public function delete(string $key): bool
    {
        $deleted = false;

        foreach ($this->table as $hashId => $row) {
            if ($row['key'] === $key) {
                $this->table->del($hashId);
                $deleted = true;
            }
        }

        return $deleted;
    }

    /**
     * Create a unique hash ID for a key-field combination
     *
     * @param string $key The hash key
     * @param string $field The field name
     * @return string The unique hash ID
     */
    private function createHashId(string $key, string $field): string
    {
        return $key . ':' . $field;
    }
}