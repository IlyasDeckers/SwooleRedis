<?php

namespace Ody\SwooleRedis\Storage;

use Ody\SwooleRedis\MemoryManager;

/**
 * Storage for sorted set values
 */
class SortedSetStorage implements StorageInterface
{
    private \Swoole\Table $metaTable;
    private \Swoole\Table $memberTable;

    public function __construct(int $tableSize = 0)
    {
        // Use MemoryManager to determine table size
        $metaTableSize = MemoryManager::getTableSize('zset', $tableSize > 0 ? $tableSize : null);
        $memberTableSize = MemoryManager::getTableSize('zset_members', $tableSize > 0 ? $tableSize * 10 : null);

        // Initialize metadata table for sorted sets
        $this->metaTable = new \Swoole\Table($metaTableSize);
        $this->metaTable->column('count', \Swoole\Table::TYPE_INT);
        $this->metaTable->create();

        // Initialize member table for sorted set elements
        $this->memberTable = new \Swoole\Table($memberTableSize);
        $this->memberTable->column('key', \Swoole\Table::TYPE_STRING, 128);      // Parent key name
        $this->memberTable->column('member', \Swoole\Table::TYPE_STRING, 256);   // Member value
        $this->memberTable->column('score', \Swoole\Table::TYPE_FLOAT);          // Score (double precision)
        $this->memberTable->create();
    }

    /**
     * Get all sorted set keys in the storage
     *
     * @return array Array of unique sorted set keys
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
     * Add one or more members with scores to a sorted set
     *
     * @param string $key The sorted set key
     * @param float $score The score to add
     * @param string $member The member to add
     * @return int 1 if member is new, 0 if member score was updated
     */
    public function zAdd(string $key, float $score, string $member): int
    {
        // Create a unique ID for this sorted set member
        $memberId = $this->createMemberId($key, $member);

        // Check if this is a new member
        $isNew = !$this->memberTable->exist($memberId);

        // Update or add the member
        $this->memberTable->set($memberId, [
            'key' => $key,
            'member' => $member,
            'score' => $score
        ]);

        // Update the metadata
        if ($isNew) {
            $count = 0;
            if ($this->metaTable->exist($key)) {
                $meta = $this->metaTable->get($key);
                $count = $meta['count'];
            }
            $this->metaTable->set($key, ['count' => $count + 1]);
        }

        return $isNew ? 1 : 0;
    }

    /**
     * Remove one or more members from a sorted set
     *
     * @param string $key The sorted set key
     * @param string $member The member to remove
     * @return int 1 if member was removed, 0 if member doesn't exist
     */
    public function zRem(string $key, string $member): int
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
     * Get the score of a member in a sorted set
     *
     * @param string $key The sorted set key
     * @param string $member The member
     * @return float|null The score or null if member doesn't exist
     */
    public function zScore(string $key, string $member): ?float
    {
        $memberId = $this->createMemberId($key, $member);

        if (!$this->memberTable->exist($memberId)) {
            return null;
        }

        $row = $this->memberTable->get($memberId);
        return $row['score'];
    }

    /**
     * Get the number of members in a sorted set
     *
     * @param string $key The sorted set key
     * @return int The number of members
     */
    public function zCard(string $key): int
    {
        if (!$this->metaTable->exist($key)) {
            return 0;
        }

        $meta = $this->metaTable->get($key);
        return $meta['count'];
    }

    /**
     * Get the number of members in a sorted set with scores between min and max
     *
     * @param string $key The sorted set key
     * @param float $min The minimum score
     * @param float $max The maximum score
     * @return int The number of members
     */
    public function zCount(string $key, float $min, float $max): int
    {
        $count = 0;

        foreach ($this->memberTable as $id => $row) {
            if ($row['key'] === $key && $row['score'] >= $min && $row['score'] <= $max) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get members with scores between min and max in a sorted set
     *
     * @param string $key The sorted set key
     * @param float $min The minimum score
     * @param float $max The maximum score
     * @param bool $withScores Whether to include scores in the result
     * @return array Array of members or [member => score] pairs
     */
    public function zRangeByScore(string $key, float $min, float $max, bool $withScores = false): array
    {
        $members = [];

        // First collect all matching members
        foreach ($this->memberTable as $id => $row) {
            if ($row['key'] === $key && $row['score'] >= $min && $row['score'] <= $max) {
                $members[] = [
                    'member' => $row['member'],
                    'score' => $row['score']
                ];
            }
        }

        // Sort by score ascending
        usort($members, function($a, $b) {
            return $a['score'] <=> $b['score'];
        });

        // Format the result
        $result = [];
        foreach ($members as $item) {
            if ($withScores) {
                $result[] = $item['member'];
                $result[] = (string)$item['score'];
            } else {
                $result[] = $item['member'];
            }
        }

        return $result;
    }

    /**
     * Get all members in a sorted set by index range
     *
     * @param string $key The sorted set key
     * @param int $start The start index
     * @param int $stop The stop index
     * @param bool $withScores Whether to include scores in the result
     * @param bool $rev Whether to return results in reverse order
     * @return array Array of members or [member => score] pairs
     */
    public function zRange(string $key, int $start, int $stop, bool $withScores = false, bool $rev = false): array
    {
        $members = [];

        // First collect all members
        foreach ($this->memberTable as $id => $row) {
            if ($row['key'] === $key) {
                $members[] = [
                    'member' => $row['member'],
                    'score' => $row['score']
                ];
            }
        }

        if (empty($members)) {
            return [];
        }

        // Sort by score
        usort($members, function($a, $b) use ($rev) {
            return $rev ? ($b['score'] <=> $a['score']) : ($a['score'] <=> $b['score']);
        });

        // Handle negative indices like Redis does
        $size = count($members);
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
        if ($start > $stop || $start >= $size) {
            return [];
        }

        // Extract the requested range
        $rangeMembers = array_slice($members, $start, $stop - $start + 1);

        // Format the result
        $result = [];
        foreach ($rangeMembers as $item) {
            if ($withScores) {
                $result[] = $item['member'];
                $result[] = (string)$item['score'];
            } else {
                $result[] = $item['member'];
            }
        }

        return $result;
    }

    /**
     * Increment the score of a member in a sorted set
     *
     * @param string $key The sorted set key
     * @param float $increment The increment amount
     * @param string $member The member
     * @return float The new score
     */
    public function zIncrBy(string $key, float $increment, string $member): float
    {
        $memberId = $this->createMemberId($key, $member);

        if (!$this->memberTable->exist($memberId)) {
            // Member doesn't exist, add it with the increment as score
            $this->zAdd($key, $increment, $member);
            return $increment;
        }

        // Get the current score
        $row = $this->memberTable->get($memberId);
        $newScore = $row['score'] + $increment;

        // Update the score
        $this->memberTable->set($memberId, [
            'key' => $key,
            'member' => $member,
            'score' => $newScore
        ]);

        return $newScore;
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
     * @param string $key The sorted set key
     * @param string $member The member
     * @return string The unique member ID
     */
    private function createMemberId(string $key, string $member): string
    {
        return $key . ':' . md5($member);
    }
}