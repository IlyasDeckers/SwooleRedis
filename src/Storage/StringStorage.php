<?php

namespace Ody\SwooleRedis\Storage;

use Ody\SwooleRedis\MemoryManager;

/**
 * Storage for string values
 */
class StringStorage implements StorageInterface
{
    private \Swoole\Table $table;

    public function __construct(int $tableSize = 0)
    {
        // Use MemoryManager to determine table size
        $tableSize = MemoryManager::getTableSize('string', $tableSize > 0 ? $tableSize : null);

        // Initialize table for string storage
        $this->table = new \Swoole\Table($tableSize);
        $this->table->column('value', \Swoole\Table::TYPE_STRING, 1024); // 1KB max value size
        $this->table->create();
    }

    /**
     * Set a string value
     *
     * @param string $key The key to set
     * @param string $value The value to set
     * @return bool True if successful
     */
    public function set(string $key, string $value): bool
    {
        return $this->table->set($key, ['value' => $value]);
    }

    /**
     * Get a string value
     *
     * @param string $key The key to get
     * @return string|null The value or null if key doesn't exist
     */
    public function get(string $key): ?string
    {
        if (!$this->exists($key)) {
            return null;
        }

        $row = $this->table->get($key);
        return $row['value'];
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $key): bool
    {
        return $this->table->exist($key);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        if (!$this->exists($key)) {
            return false;
        }

        return $this->table->del($key);
    }

    /**
     * Get all keys (for iteration)
     *
     * @return \Swoole\Table
     */
    public function getTable(): \Swoole\Table
    {
        return $this->table;
    }
}