<?php

namespace Ody\SwooleRedis\Storage;

/**
 * Manages key expiration
 */
class KeyExpiry
{
    private \Swoole\Table $expiryTable;

    public function __construct(int $tableSize = 1024 * 1024)
    {
        // Initialize table for key expiration
        $this->expiryTable = new \Swoole\Table($tableSize);
        $this->expiryTable->column('expire_at', \Swoole\Table::TYPE_INT);
        $this->expiryTable->create();
    }

    /**
     * Set expiration time for a key
     *
     * @param string $key The key to set expiration for
     * @param int $seconds Time to live in seconds
     * @return bool True if successful
     */
    public function setExpiration(string $key, int $seconds): bool
    {
        return $this->expiryTable->set($key, ['expire_at' => time() + $seconds]);
    }

    /**
     * Get remaining TTL for a key
     *
     * @param string $key The key to check
     * @return int Remaining TTL in seconds, -1 if no TTL set, -2 if key doesn't exist
     */
    public function getTtl(string $key): int
    {
        if (!$this->expiryTable->exist($key)) {
            return -1; // No expiration
        }

        $expiry = $this->expiryTable->get($key);
        $ttl = $expiry['expire_at'] - time();

        return max(0, $ttl); // Don't return negative TTL
    }

    /**
     * Check if a key is expired
     *
     * @param string $key The key to check
     * @return bool True if expired, false otherwise
     */
    public function isExpired(string $key): bool
    {
        if (!$this->expiryTable->exist($key)) {
            return false;
        }

        $expiry = $this->expiryTable->get($key);
        return time() > $expiry['expire_at'];
    }

    /**
     * Remove expiration for a key
     *
     * @param string $key The key to remove expiration for
     * @return bool True if successful
     */
    public function removeExpiration(string $key): bool
    {
        if (!$this->expiryTable->exist($key)) {
            return false;
        }

        return $this->expiryTable->del($key);
    }

    /**
     * Check for and remove expired keys
     *
     * @param StorageInterface $storage The storage to check against
     */
    public function checkExpirations(StorageInterface $storage): void
    {
        foreach ($this->expiryTable as $key => $row) {
            if (time() > $row['expire_at']) {
                // Remove the expired key from storage
                $storage->delete($key);

                // Remove the expiration entry
                $this->expiryTable->del($key);
            }
        }
    }
}