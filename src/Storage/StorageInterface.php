<?php

namespace Ody\SwooleRedis\Storage;

/**
 * Interface for data storage implementations
 */
interface StorageInterface
{
    /**
     * Check if a key exists
     *
     * @param string $key The key to check
     * @return bool True if the key exists, false otherwise
     */
    public function exists(string $key): bool;

    /**
     * Delete a key
     *
     * @param string $key The key to delete
     * @return bool True if the key was deleted, false otherwise
     */
    public function delete(string $key): bool;
}