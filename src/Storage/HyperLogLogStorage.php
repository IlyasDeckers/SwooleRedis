<?php

namespace Ody\SwooleRedis\Storage;

use Ody\SwooleRedis\MemoryManager;

/**
 * Storage for HyperLogLog probabilistic data structure using sparse representation
 * to reduce memory usage
 */
class HyperLogLogStorage
{
    private StringStorage $stringStorage;

    // HyperLogLog precision parameters - reduced for memory efficiency
    private const HLL_P = 12;               // 2^12 registers = 4096 (reduced from 14/16384)
    private const HLL_REGISTERS = 4096;     // 2^12 registers
    private const HLL_ALPHA = 0.7213 / (1 + 1.079 / self::HLL_REGISTERS);  // Correction factor

    // Magic string to identify a HyperLogLog
    private const HLL_MAGIC = "HLL2";       // First 4 bytes of HLL encoded strings

    public function __construct(StringStorage $stringStorage)
    {
        $this->stringStorage = $stringStorage;
    }

    /**
     * Add an element to a HyperLogLog data structure
     *
     * @param string $key The key
     * @param string $element The element to add
     * @return int 1 if the cardinality changed, 0 otherwise
     */
    public function pfAdd(string $key, string $element): int
    {
        // Get the current HLL structure or create a new one
        $hllData = $this->getOrCreateHLL($key);

        // Hash the element and compute its register index and count of leading zeros
        list($index, $rank) = $this->hashElement($element);

        // Check if we need to update the HLL
        $updated = false;
        if (!isset($hllData[$index]) || $hllData[$index] < $rank) {
            $hllData[$index] = $rank;
            $updated = true;
        }

        // If updated, store the new HLL
        if ($updated) {
            $this->saveHLL($key, $hllData);
            return 1;
        }

        return 0;
    }

    /**
     * Get the estimated cardinality of a HyperLogLog data structure
     *
     * @param string $key The key
     * @return int The estimated cardinality
     */
    public function pfCount(string $key): int
    {
        $hllData = $this->loadHLL($key);

        if (empty($hllData)) {
            return 0;
        }

        return $this->estimateCardinality($hllData);
    }

    /**
     * Get the estimated cardinality across multiple HyperLogLog structures
     *
     * @param array $keys The keys
     * @return int The estimated cardinality of the union
     */
    public function pfCountMultiple(array $keys): int
    {
        if (empty($keys)) {
            return 0;
        }

        if (count($keys) === 1) {
            return $this->pfCount($keys[0]);
        }

        // Create a merged HLL with maximum values across all HLLs
        $mergedHLL = [];

        foreach ($keys as $key) {
            $hllData = $this->loadHLL($key);

            if (empty($hllData)) {
                continue;
            }

            // Merge by taking max values
            foreach ($hllData as $index => $rank) {
                if (!isset($mergedHLL[$index]) || $rank > $mergedHLL[$index]) {
                    $mergedHLL[$index] = $rank;
                }
            }
        }

        if (empty($mergedHLL)) {
            return 0;
        }

        return $this->estimateCardinality($mergedHLL);
    }

    /**
     * Merge multiple HyperLogLog data structures
     *
     * @param string $destKey The destination key
     * @param array $sourceKeys The source keys
     * @return bool True if successful
     */
    public function pfMerge(string $destKey, array $sourceKeys): bool
    {
        if (empty($sourceKeys)) {
            return false;
        }

        // Start with an empty HLL
        $mergedHLL = [];

        // Merge all source HLLs
        foreach ($sourceKeys as $key) {
            $hllData = $this->loadHLL($key);

            if (empty($hllData)) {
                continue;
            }

            // Merge by taking max values
            foreach ($hllData as $index => $rank) {
                if (!isset($mergedHLL[$index]) || $rank > $mergedHLL[$index]) {
                    $mergedHLL[$index] = $rank;
                }
            }
        }

        // Save the merged HLL
        $this->saveHLL($destKey, $mergedHLL);

        return true;
    }

    /**
     * Get or create a HyperLogLog data structure
     *
     * @param string $key The key
     * @return array The HLL data structure as [index => rank]
     */
    private function getOrCreateHLL(string $key): array
    {
        return $this->loadHLL($key);
    }

    /**
     * Load a HyperLogLog structure from storage
     *
     * @param string $key The key
     * @return array The HLL data as [index => rank]
     */
    private function loadHLL(string $key): array
    {
        $rawData = $this->stringStorage->get($key);

        if ($rawData === null) {
            return [];
        }

        // Check for the magic header
        if (substr($rawData, 0, 4) !== self::HLL_MAGIC) {
            return [];
        }

        // Decode the sparse representation
        $hllData = [];
        $data = substr($rawData, 4);

        // Simple format: 2 bytes for index, 1 byte for rank
        for ($i = 0; $i < strlen($data); $i += 3) {
            if ($i + 2 >= strlen($data)) break;

            $index = (ord($data[$i]) << 8) | ord($data[$i+1]);
            $rank = ord($data[$i+2]);

            $hllData[$index] = $rank;
        }

        return $hllData;
    }

    /**
     * Save a HyperLogLog structure to storage
     *
     * @param string $key The key
     * @param array $hllData The HLL data as [index => rank]
     * @return bool Success
     */
    private function saveHLL(string $key, array $hllData): bool
    {
        // Start with the magic header
        $encoded = self::HLL_MAGIC;

        // Encode the sparse representation
        foreach ($hllData as $index => $rank) {
            // 2 bytes for index, 1 byte for rank
            $encoded .= chr(($index >> 8) & 0xFF) . chr($index & 0xFF) . chr($rank & 0xFF);
        }

        return $this->stringStorage->set($key, $encoded);
    }

    /**
     * Hash an element and compute its register index and rank
     *
     * @param string $element The element
     * @return array [register_index, rank]
     */
    private function hashElement(string $element): array
    {
        // Use a 32-bit hash function
        $hash = crc32($element);

        // Extract register index (first P bits)
        $index = $hash & (self::HLL_REGISTERS - 1);

        // Compute the rank (position of leftmost 1-bit in the remaining bits + 1)
        $hash = $hash >> self::HLL_P; // Remove the bits used for index

        // Count leading zeros and add 1
        // PHP_INT_SIZE * 8 - 32 accounts for the fact that crc32 produces 32-bit values
        // but PHP might use 64-bit integers
        $offset = PHP_INT_SIZE * 8 - 32;
        $zeros = min(1 + $offset + clz($hash), 255);

        return [$index, $zeros];
    }

    /**
     * Estimate the cardinality from register values
     *
     * @param array $registers The register values as [index => rank]
     * @return int The estimated cardinality
     */
    private function estimateCardinality(array $registers): int
    {
        // We need to account for empty registers when using sparse representation
        $m = self::HLL_REGISTERS;
        $emptyRegisters = $m - count($registers);

        if ($emptyRegisters === $m) {
            return 0; // All registers are empty
        }

        // If almost all registers are empty, use linear counting
        if ($emptyRegisters > 0.7 * $m) {
            return round($m * log($m / $emptyRegisters));
        }

        // Calculate the harmonic mean
        $sum = 0;

        // Process non-empty registers
        foreach ($registers as $rank) {
            $sum += pow(2, -$rank);
        }

        // Add contribution from empty registers (2^0 = 1)
        $sum += $emptyRegisters;

        // Apply the correction formula
        $estimate = self::HLL_ALPHA * $m * $m / $sum;

        // Apply small range correction
        if ($estimate <= 2.5 * $m) {
            // Count number of registers with value 0
            if ($emptyRegisters > 0) {
                // Linear counting for small cardinalities
                $estimate = $m * log($m / $emptyRegisters);
            }
        }
        // Apply large range correction but only for the original 2^14 range
        else if (self::HLL_REGISTERS == 16384 && $estimate > pow(2, 32) / 30) {
            $estimate = -pow(2, 32) * log(1 - $estimate / pow(2, 32));
        }

        return (int)round($estimate);
    }
}

/**
 * Count leading zeros implementation for PHP (32-bit value)
 */
function clz($x) {
    if ($x == 0) return 32;

    $n = 0;
    if ($x <= 0x0000FFFF) { $n += 16; $x <<= 16; }
    if ($x <= 0x00FFFFFF) { $n += 8; $x <<= 8; }
    if ($x <= 0x0FFFFFFF) { $n += 4; $x <<= 4; }
    if ($x <= 0x3FFFFFFF) { $n += 2; $x <<= 2; }
    if ($x <= 0x7FFFFFFF) { $n += 1; }

    return $n;
}