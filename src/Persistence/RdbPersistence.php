<?php

namespace Ody\SwooleRedis\Persistence;

use Ody\SwooleRedis\Storage\StringStorage;
use Ody\SwooleRedis\Storage\HashStorage;
use Ody\SwooleRedis\Storage\ListStorage;
use Ody\SwooleRedis\Storage\KeyExpiry;

/**
 * RDB persistence implementation
 * Saves complete point-in-time snapshots of the dataset
 */
class RdbPersistence implements PersistenceInterface
{
    private StringStorage $stringStorage;
    private HashStorage $hashStorage;
    private ListStorage $listStorage;
    private KeyExpiry $keyExpiry;
    private string $dbFilename;
    private string $dir;

    /**
     * @param string $dir Directory to store RDB files
     * @param string $dbFilename Filename for the RDB file
     */
    public function __construct(string $dir = '/tmp', string $dbFilename = 'dump.rdb')
    {
        $this->dir = rtrim($dir, '/');
        $this->dbFilename = $dbFilename;
    }

    /**
     * Set the storage repositories
     */
    public function setStorageRepositories(
        StringStorage $stringStorage,
        HashStorage $hashStorage,
        ListStorage $listStorage,
        KeyExpiry $keyExpiry
    ): void {
        $this->stringStorage = $stringStorage;
        $this->hashStorage = $hashStorage;
        $this->listStorage = $listStorage;
        $this->keyExpiry = $keyExpiry;
    }

    /**
     * Save the current dataset to a file
     */
    public function save(): bool
    {
        $data = [
            'metadata' => [
                'version' => '1.0',
                'created_at' => time(),
            ],
            'data' => [
                'strings' => $this->extractStringData(),
                'hashes' => $this->extractHashData(),
                'lists' => $this->extractListData(),
                'expiry' => $this->extractExpiryData(),
            ],
        ];

        // Create temporary file and write serialized data
        $tempFilename = $this->dir . '/' . uniqid('temp_') . '.rdb';
        $success = file_put_contents($tempFilename, serialize($data));

        if ($success === false) {
            echo "Error writing to temporary file: $tempFilename\n";
            return false;
        }

        // Atomically replace the old file with the new one
        $targetFile = $this->dir . '/' . $this->dbFilename;
        if (!rename($tempFilename, $targetFile)) {
            echo "Error replacing file: $targetFile\n";
            @unlink($tempFilename);
            return false;
        }

        echo "RDB saved successfully to: $targetFile\n";
        return true;
    }

    /**
     * Load dataset from a file
     */
    public function load(): bool
    {
        $filename = $this->dir . '/' . $this->dbFilename;

        if (!file_exists($filename)) {
            echo "No RDB file found at: $filename\n";
            return false;
        }

        $data = @unserialize(file_get_contents($filename));

        if ($data === false) {
            echo "Error unserializing RDB file\n";
            return false;
        }

        // Load string data
        if (isset($data['data']['strings'])) {
            foreach ($data['data']['strings'] as $key => $value) {
                $this->stringStorage->set($key, $value);
            }
        }

        // Load hash data
        if (isset($data['data']['hashes'])) {
            foreach ($data['data']['hashes'] as $key => $hash) {
                foreach ($hash as $field => $value) {
                    $this->hashStorage->hSet($key, $field, $value);
                }
            }
        }

        // Load list data
        if (isset($data['data']['lists'])) {
            foreach ($data['data']['lists'] as $key => $list) {
                foreach ($list as $value) {
                    $this->listStorage->rpush($key, $value);
                }
            }
        }

        // Load expiry data
        if (isset($data['data']['expiry'])) {
            $now = time();
            foreach ($data['data']['expiry'] as $key => $expireAt) {
                // Only set expiration if it hasn't already expired
                if ($expireAt > $now) {
                    $ttl = $expireAt - $now;
                    $this->keyExpiry->setExpiration($key, $ttl);
                } else {
                    // Key has expired, delete it from storage
                    $this->stringStorage->delete($key);
                    $this->hashStorage->delete($key);
                    $this->listStorage->delete($key);
                }
            }
        }

        echo "RDB loaded successfully from: $filename\n";
        return true;
    }

    /**
     * Extract all string data for serialization
     */
    private function extractStringData(): array
    {
        $data = [];
        $table = $this->stringStorage->getTable();

        foreach ($table as $key => $row) {
            // Skip keys that have expired
            if ($this->keyExpiry->isExpired($key)) {
                continue;
            }

            $data[$key] = $row['value'];
        }

        return $data;
    }

    /**
     * Extract all hash data for serialization
     */
    private function extractHashData(): array
    {
        // This is a simplified approach - in a full implementation
        // we would need more efficient access to the hash storage
        $data = [];

        // Get all hash keys
        $keys = $this->hashStorage->getAllKeys();

        foreach ($keys as $key) {
            // Skip keys that have expired
            if ($this->keyExpiry->isExpired($key)) {
                continue;
            }

            $data[$key] = $this->hashStorage->hGetAll($key);
        }

        return $data;
    }

    /**
     * Extract all list data for serialization
     */
    private function extractListData(): array
    {
        $data = [];

        // Get all list keys
        $keys = $this->listStorage->getAllKeys();

        foreach ($keys as $key) {
            // Skip keys that have expired
            if ($this->keyExpiry->isExpired($key)) {
                continue;
            }

            $meta = $this->listStorage->getMetadata($key);
            if ($meta && $meta['size'] > 0) {
                $data[$key] = $this->listStorage->lrange($key, 0, -1);
            }
        }

        return $data;
    }

    /**
     * Extract all expiry data for serialization
     */
    private function extractExpiryData(): array
    {
        $data = [];
        $expiryData = $this->keyExpiry->getAllExpiry();

        foreach ($expiryData as $key => $expireAt) {
            // Only include non-expired keys
            if ($expireAt > time()) {
                $data[$key] = $expireAt;
            }
        }

        return $data;
    }
}