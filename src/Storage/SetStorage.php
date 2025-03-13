<?php

namespace Ody\SwooleRedis\Storage;

use Ody\SwooleRedis\MemoryManager;

/**
 * Storage for set values
 */
class SetStorage implements StorageInterface
{
    private \Swoole\Table $metaTable;
    private \Swoole\Table $memberTable;

    public function __construct(int $tableSize = 0)
    {
        // Use MemoryManager to determine table size
        $metaTableSize = MemoryManager::getTableSize('set', $tableSize > 0 ? $tableSize : null);
        $memberTableSize = MemoryManager::getTableSize('set_members', $tableSize > 0 ? $tableSize * 10 : null);

        // Initialize metadata table for sets
        $this->metaTable = new \Swoole\Table($metaTableSize);
        $this->metaTable->column('count', \Swoole\Table::TYPE_INT);
        $this->metaTable->create();

        // Initialize member table for set elements
        $this->memberTable = new \Swoole\Table($memberTableSize);
        $this->memberTable->column('key', \Swoole\Table::TYPE_STRING, 128);      // Parent key name
        $this->memberTable->column('member', \Swoole\Table::TYPE_STRING, 512);   // Member value
        $this->memberTable->create();
    }

    /**
     * Get all set keys in the storage
     *
     * @return array Array of unique set keys
     */
    public function getAllKeys(): array
    {
        $keys = [];
        foreach ($this->metaTable as $key => $row) {
            $keys[] = $key;
        }
        return $keys;
    }

    /**
     * Add one or more members to a set
     *
     * @param string $key The set key
     * @param string $member The member to add
     * @return int 1 if member is new, 0 if member already exists
     */
    public function sAdd(string $key, string $member): int
    {
        // Create a unique ID for this set member
        $memberId = $this->createMemberId($key, $member);

        // Check if this member already exists
        if ($this->memberTable->exist($memberId)) {
            return 0;
        }

        // Add the member
        $this->memberTable->set($memberId, [
            'key' => $key,
            'member' => $member
        ]);

        // Update the metadata
        $count = 0;
        if ($this->metaTable->exist($key)) {
            $meta = $this->metaTable->get($key);
            $count = $meta['count'];
        }
        $this->metaTable->set($key, ['count' => $count + 1]);

        return 1;
    }

    /**
     * Remove one or more members from a set
     *
     * @param string $key The set key
     * @param string $member The member to remove
     * @return int 1 if member was removed, 0 if member doesn't exist
     */
    public function sRem(string $key, string $member): int
    {
        $memberId = $this->createMemberId($key, $member);

        if (!$this->memberTable->exist($memberId)) {
            return 0;
        }

        // Remove the member
        $this->memberTable->del($memberId);

        // Update the metadata
        if ($this->metaTable->exist($key)) {
            $meta = $this->metaTable->get($key);
            $count = max(0, $meta['count'] - 1);

            if ($count === 0) {
                $this->metaTable->del($key);
            } else {
                $this->metaTable->set($key, ['count' => $count]);
            }
        }

        return 1;
    }

    /**
     * Check if a member exists in a set
     *
     * @param string $key The set key
     * @param string $member The member to check
     * @return bool True if the member exists, false otherwise
     */
    public function sIsMember(string $key, string $member): bool
    {
        $memberId = $this->createMemberId($key, $member);
        return $this->memberTable->exist($memberId);
    }

    /**
     * Get all members in a set
     *
     * @param string $key The set key
     * @return array Array of members
     */
    public function sMembers(string $key): array
    {
        $members = [];

        foreach ($this->memberTable as $id => $row) {
            if ($row['key'] === $key) {
                $members[] = $row['member'];
            }
        }

        return $members;
    }

    /**
     * Get the number of members in a set
     *
     * @param string $key The set key
     * @return int The number of members
     */
    public function sCard(string $key): int
    {
        if (!$this->metaTable->exist($key)) {
            return 0;
        }

        $meta = $this->metaTable->get($key);
        return $meta['count'];
    }

    /**
     * Move a member from one set to another
     *
     * @param string $srcKey The source set key
     * @param string $dstKey The destination set key
     * @param string $member The member to move
     * @return int 1 if the member was moved, 0 if member isn't in source or already in destination
     */
    public function sMove(string $srcKey, string $dstKey, string $member): int
    {
        if (!$this->sIsMember($srcKey, $member)) {
            return 0;
        }

        if ($this->sIsMember($dstKey, $member)) {
            $this->sRem($srcKey, $member);
            return 1;
        }

        $this->sRem($srcKey, $member);
        $this->sAdd($dstKey, $member);

        return 1;
    }

    /**
     * Get a random member from a set
     *
     * @param string $key The set key
     * @param int $count Number of members to return
     * @return string|array|null One or more random members, or null if set is empty
     */
    public function sRandMember(string $key, int $count = 1)
    {
        $members = $this->sMembers($key);

        if (empty($members)) {
            return null;
        }

        if ($count === 1) {
            return $members[array_rand($members)];
        }

        $result = [];
        $sampleSize = abs($count);

        if ($count < 0) {
            // Negative count means allowing duplicates
            for ($i = 0; $i < $sampleSize; $i++) {
                $result[] = $members[array_rand($members)];
            }
        } else {
            // Positive count means no duplicates
            $sampleSize = min($sampleSize, count($members));
            $keys = array_rand($members, $sampleSize);

            if (!is_array($keys)) {
                $keys = [$keys];
            }

            foreach ($keys as $key) {
                $result[] = $members[$key];
            }
        }

        return $result;
    }

    /**
     * Pop a random member from a set
     *
     * @param string $key The set key
     * @return string|null The popped member, or null if set is empty
     */
    public function sPop(string $key): ?string
    {
        $member = $this->sRandMember($key);

        if ($member === null) {
            return null;
        }

        $this->sRem($key, $member);
        return $member;
    }

    /**
     * Compute the intersection of multiple sets
     *
     * @param array $keys Array of set keys
     * @return array Array of members in the intersection
     */
    public function sInter(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        if (count($keys) === 1) {
            return $this->sMembers($keys[0]);
        }

        // Get members of the first set
        $result = $this->sMembers($keys[0]);

        // Intersect with each remaining set
        for ($i = 1; $i < count($keys); $i++) {
            $set = $this->sMembers($keys[$i]);
            $result = array_intersect($result, $set);

            // Early termination if intersection is empty
            if (empty($result)) {
                return [];
            }
        }

        return array_values($result);
    }

    /**
     * Compute the union of multiple sets
     *
     * @param array $keys Array of set keys
     * @return array Array of members in the union
     */
    public function sUnion(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        $result = [];

        foreach ($keys as $key) {
            $members = $this->sMembers($key);
            $result = array_merge($result, $members);
        }

        return array_values(array_unique($result));
    }

    /**
     * Compute the difference between the first set and all successive sets
     *
     * @param array $keys Array of set keys
     * @return array Array of members in the difference
     */
    public function sDiff(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        if (count($keys) === 1) {
            return $this->sMembers($keys[0]);
        }

        // Get members of the first set
        $result = $this->sMembers($keys[0]);

        // Calculate difference with each remaining set
        for ($i = 1; $i < count($keys); $i++) {
            $set = $this->sMembers($keys[$i]);
            $result = array_diff($result, $set);

            // Early termination if difference is empty
            if (empty($result)) {
                return [];
            }
        }

        return array_values($result);
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

        // Delete all members
        foreach ($this->memberTable as $id => $row) {
            if ($row['key'] === $key) {
                $this->memberTable->del($id);
            }
        }

        // Delete metadata
        return $this->metaTable->del($key);
    }

    /**
     * Create a unique member ID for a key-member combination
     *
     * @param string $key The set key
     * @param string $member The member
     * @return string The unique member ID
     */
    private function createMemberId(string $key, string $member): string
    {
        return $key . ':' . md5($member);
    }
}