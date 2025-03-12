<?php

namespace Ody\SwooleRedis\Storage;

use Swoole\Table;

/**
 * Storage for list values
 */
class ListStorage implements StorageInterface
{
    private Table $metaTable;
    private Table $itemTable;

    public function __construct(int $tableSize = 1024 * 1024)
    {
        // Initialize metadata table for lists
        $this->metaTable = new Table($tableSize);
        $this->metaTable->column('head', Table::TYPE_INT);
        $this->metaTable->column('tail', Table::TYPE_INT);
        $this->metaTable->column('size', Table::TYPE_INT);
        $this->metaTable->create();

        // Initialize item table for list elements
        $this->itemTable = new Table($tableSize * 10); // More items than lists
        $this->itemTable->column('list_key', Table::TYPE_STRING, 128);
        $this->itemTable->column('index', Table::TYPE_INT);
        $this->itemTable->column('value', Table::TYPE_STRING, 1024);
        $this->itemTable->create();
    }

    /**
     * Get all list keys in the storage
     *
     * @return array Array of list keys
     */
    public function getAllKeys(): array
    {
        $keys = [];

        foreach ($this->metaTable as $key => $value) {
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * Get metadata for a specific list
     *
     * @param string $key The list key
     * @return array|null Metadata array or null if not found
     */
    public function getMetadata(string $key): ?array
    {
        if (!$this->metaTable->exist($key)) {
            return null;
        }

        return $this->metaTable->get($key);
    }

    /**
     * Push a value to the front of a list
     *
     * @param string $key The list key
     * @param string $value The value to push
     * @return int The new list length
     */
    public function lpush(string $key, string $value): int
    {
        if (!$this->metaTable->exist($key)) {
            // Initialize a new list
            $this->metaTable->set($key, [
                'head' => 0,
                'tail' => 0,
                'size' => 1
            ]);

            // Add the first element
            $this->itemTable->set($this->createItemId($key, 0), [
                'list_key' => $key,
                'index' => 0,
                'value' => $value
            ]);

            return 1;
        }

        // Get list metadata
        $meta = $this->metaTable->get($key);
        $newHead = $meta['head'] - 1;
        $newSize = $meta['size'] + 1;

        // Add the new element
        $this->itemTable->set($this->createItemId($key, $newHead), [
            'list_key' => $key,
            'index' => $newHead,
            'value' => $value
        ]);

        // Update list metadata
        $this->metaTable->set($key, [
            'head' => $newHead,
            'tail' => $meta['tail'],
            'size' => $newSize
        ]);

        return $newSize;
    }

    /**
     * Push a value to the back of a list
     *
     * @param string $key The list key
     * @param string $value The value to push
     * @return int The new list length
     */
    public function rpush(string $key, string $value): int
    {
        if (!$this->metaTable->exist($key)) {
            // Initialize a new list
            $this->metaTable->set($key, [
                'head' => 0,
                'tail' => 0,
                'size' => 1
            ]);

            // Add the first element
            $this->itemTable->set($this->createItemId($key, 0), [
                'list_key' => $key,
                'index' => 0,
                'value' => $value
            ]);

            return 1;
        }

        // Get list metadata
        $meta = $this->metaTable->get($key);
        $newTail = $meta['tail'] + 1;
        $newSize = $meta['size'] + 1;

        // Add the new element
        $this->itemTable->set($this->createItemId($key, $newTail), [
            'list_key' => $key,
            'index' => $newTail,
            'value' => $value
        ]);

        // Update list metadata
        $this->metaTable->set($key, [
            'head' => $meta['head'],
            'tail' => $newTail,
            'size' => $newSize
        ]);

        return $newSize;
    }

    /**
     * Pop a value from the front of a list
     *
     * @param string $key The list key
     * @return string|null The popped value or null if list is empty
     */
    public function lpop(string $key): ?string
    {
        if (!$this->metaTable->exist($key)) {
            return null;
        }

        // Get list metadata
        $meta = $this->metaTable->get($key);

        if ($meta['size'] === 0) {
            return null;
        }

        // Get the item at the head
        $itemId = $this->createItemId($key, $meta['head']);
        $item = $this->itemTable->get($itemId);

        if (!$item) {
            return null;
        }

        $value = $item['value'];

        // Remove the item
        $this->itemTable->del($itemId);

        // Update list metadata
        $newHead = $meta['head'] + 1;
        $newSize = $meta['size'] - 1;

        if ($newSize === 0) {
            // List is now empty, delete it
            $this->metaTable->del($key);
        } else {
            $this->metaTable->set($key, [
                'head' => $newHead,
                'tail' => $meta['tail'],
                'size' => $newSize
            ]);
        }

        return $value;
    }

    /**
     * Pop a value from the back of a list
     *
     * @param string $key The list key
     * @return string|null The popped value or null if list is empty
     */
    public function rpop(string $key): ?string
    {
        if (!$this->metaTable->exist($key)) {
            return null;
        }

        // Get list metadata
        $meta = $this->metaTable->get($key);

        if ($meta['size'] === 0) {
            return null;
        }

        // Get the item at the tail
        $itemId = $this->createItemId($key, $meta['tail']);
        $item = $this->itemTable->get($itemId);

        if (!$item) {
            return null;
        }

        $value = $item['value'];

        // Remove the item
        $this->itemTable->del($itemId);

        // Update list metadata
        $newTail = $meta['tail'] - 1;
        $newSize = $meta['size'] - 1;

        if ($newSize === 0) {
            // List is now empty, delete it
            $this->metaTable->del($key);
        } else {
            $this->metaTable->set($key, [
                'head' => $meta['head'],
                'tail' => $newTail,
                'size' => $newSize
            ]);
        }

        return $value;
    }

    /**
     * Get all items in a list
     *
     * @param string $key The list key
     * @return array An array of list items
     */
    public function lrange(string $key, int $start, int $stop): array
    {
        if (!$this->metaTable->exist($key)) {
            return [];
        }

        // Get list metadata
        $meta = $this->metaTable->get($key);

        if ($meta['size'] === 0) {
            return [];
        }

        $result = [];
        $head = $meta['head'];
        $size = $meta['size'];

        // Adjust negative indices (like in Redis)
        if ($start < 0) {
            $start = $size + $start;
        }
        if ($stop < 0) {
            $stop = $size + $stop;
        }

        // Ensure valid bounds
        $start = max(0, $start);
        $stop = min($size - 1, $stop);

        // Return empty if invalid range
        if ($start > $stop) {
            return [];
        }

        // Extract the requested range
        for ($i = $start; $i <= $stop; $i++) {
            $itemId = $this->createItemId($key, $head + $i);
            $item = $this->itemTable->get($itemId);

            if ($item) {
                $result[] = $item['value'];
            }
        }

        return $result;
    }

    /**
     * Get length of a list
     *
     * @param string $key The list key
     * @return int The list length
     */
    public function llen(string $key): int
    {
        if (!$this->metaTable->exist($key)) {
            return 0;
        }

        $meta = $this->metaTable->get($key);
        return $meta['size'];
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $key): bool
    {
        return $this->metaTable->exist($key);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        if (!$this->metaTable->exist($key)) {
            return false;
        }

        // Get list metadata
        $meta = $this->metaTable->get($key);

        // Delete all list items
        for ($i = $meta['head']; $i <= $meta['tail']; $i++) {
            $itemId = $this->createItemId($key, $i);
            $this->itemTable->del($itemId);
        }

        // Delete list metadata
        return $this->metaTable->del($key);
    }

    /**
     * Create a unique item ID for a list element
     *
     * @param string $key The list key
     * @param int $index The element index
     * @return string The unique item ID
     */
    private function createItemId(string $key, int $index): string
    {
        return $key . ':' . $index;
    }
}