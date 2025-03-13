<?php

namespace Ody\SwooleRedis;

/**
 * Memory Manager for dynamic allocation of Swoole Tables
 */
class MemoryManager
{
    /**
     * Get recommended table size based on system memory
     *
     * @param string $tableType Type of table ('string', 'hash', 'list', 'list_items', 'expiry', etc.)
     * @param int|null $requestedSize Requested size, or null for auto-detection
     * @return int Table size
     */
    public static function getTableSize(string $tableType, ?int $requestedSize = null): int
    {
        // If size specifically requested, use it
        if ($requestedSize !== null) {
            return $requestedSize;
        }

        // Get available system memory in bytes
        $availableMemory = self::getAvailableMemory();

        // Determine what percentage of memory to use based on table type
        // More conservative percentages to avoid excessive allocation
        $percentages = [
            'string' => 0.08,    // 8% for string storage (reduced from 15%)
            'hash' => 0.08,      // 8% for hash storage (reduced from 15%)
            'list' => 0.03,      // 3% for list metadata (reduced from 5%)
            'list_items' => 0.15, // 15% for list items (reduced from 30%)
            'set' => 0.05,       // 5% for set metadata
            'set_members' => 0.10, // 10% for set members
            'zset' => 0.05,      // 5% for sorted set metadata
            'zset_members' => 0.10, // 10% for sorted set members
            'expiry' => 0.03,    // 3% for expiry info (reduced from 5%)
            'default' => 0.05    // 5% for any other tables (reduced from 10%)
        ];

        $percentage = $percentages[$tableType] ?? $percentages['default'];

        // Calculate size based on percentage of available memory
        // And convert to number of rows (rough estimation)
        $memoryToUse = $availableMemory * $percentage;

        // Estimate average row size based on table type (in bytes)
        $avgRowSizes = [
            'string' => 4200,  // key + 4KB value + overhead (increased from 1200)
            'hash' => 300,     // key + field + value + overhead
            'list' => 80,      // Metadata is smaller
            'list_items' => 1200, // key + value + overhead
            'set' => 80,       // Metadata is smaller
            'set_members' => 600, // key + member + overhead
            'zset' => 80,      // Metadata is smaller
            'zset_members' => 700, // key + member + score + overhead
            'expiry' => 40,    // key + timestamp
            'default' => 500   // default estimation
        ];

        $rowSize = $avgRowSizes[$tableType] ?? $avgRowSizes['default'];

        // Calculate number of rows
        $rowCount = (int)($memoryToUse / $rowSize);

        // Set some reasonable bounds - make minimum smaller and max much smaller
        $minRows = 128;  // Reduced from 1024
        $maxRows = 1024 * 1024;  // Reduced from 10M to 1M

        // Clamp the result between min and max
        $result = max($minRows, min($rowCount, $maxRows));

        // For non-dev environments, apply a safety factor
        if (!defined('SWOOLE_REDIS_DEV_MODE') || !SWOOLE_REDIS_DEV_MODE) {
            // Start with more conservative values, can be increased later
            return min($result, 4096); // Limit to 4K rows initially
        }

        return $result;
    }

    /**
     * Get available system memory in bytes
     *
     * @return int Available memory in bytes
     */
    private static function getAvailableMemory(): int
    {
        // Default to a conservative value if we can't determine
        $defaultMemory = 256 * 1024 * 1024; // 256 MB (reduced from 512 MB)

        if (PHP_OS_FAMILY === 'Linux') {
            // Try to get from /proc/meminfo
            $memInfo = @file_get_contents('/proc/meminfo');
            if ($memInfo) {
                // Try to get MemAvailable first (more accurate)
                if (preg_match('/MemAvailable:\s+(\d+)\s+kB/i', $memInfo, $matches)) {
                    return (int)$matches[1] * 1024;
                }

                // Fall back to MemFree if MemAvailable isn't available
                if (preg_match('/MemFree:\s+(\d+)\s+kB/i', $memInfo, $matches)) {
                    return (int)$matches[1] * 1024;
                }

                // Last resort: use MemTotal and take a percentage
                if (preg_match('/MemTotal:\s+(\d+)\s+kB/i', $memInfo, $matches)) {
                    // Use 25% of total as a conservative estimate (reduced from 40%)
                    return (int)($matches[1] * 1024 * 0.25);
                }
            }
        }

        // For other OS, use PHP's memory_limit as a guide
        $memoryLimit = self::parseMemoryLimit();
        if ($memoryLimit > 0) {
            // Use 25% of PHP's memory limit (reduced from 40%)
            return (int)($memoryLimit * 0.25);
        }

        return $defaultMemory;
    }

    /**
     * Parse PHP's memory_limit setting
     *
     * @return int Memory limit in bytes
     */
    private static function parseMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');

        if ($limit === '-1') {
            // Unlimited, use a default value
            return 512 * 1024 * 1024; // 512 MB (reduced from 1 GB)
        }

        $value = (int)$limit;

        // Convert to bytes based on suffix
        if (stripos($limit, 'K') !== false) {
            $value *= 1024;
        } else if (stripos($limit, 'M') !== false) {
            $value *= 1024 * 1024;
        } else if (stripos($limit, 'G') !== false) {
            $value *= 1024 * 1024 * 1024;
        }

        return $value;
    }
}