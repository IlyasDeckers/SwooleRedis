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
        $percentages = [
            'string' => 0.15,   // 15% for string storage
            'hash' => 0.15,     // 15% for hash storage
            'list' => 0.05,     // 5% for list metadata
            'list_items' => 0.3, // 30% for list items
            'expiry' => 0.05,   // 5% for expiry info
            'default' => 0.1    // 10% for any other tables
        ];

        $percentage = $percentages[$tableType] ?? $percentages['default'];

        // Calculate size based on percentage of available memory
        // And convert to number of rows (rough estimation)
        $memoryToUse = $availableMemory * $percentage;

        // Estimate average row size based on table type (in bytes)
        $avgRowSizes = [
            'string' => 1200,  // key + 1KB value + overhead
            'hash' => 300,     // key + field + value + overhead
            'list' => 80,      // Metadata is smaller
            'list_items' => 1200, // key + value + overhead
            'expiry' => 40,    // key + timestamp
            'default' => 500   // default estimation
        ];

        $rowSize = $avgRowSizes[$tableType] ?? $avgRowSizes['default'];

        // Calculate number of rows
        $rowCount = (int)($memoryToUse / $rowSize);

        // Set some reasonable bounds
        $minRows = 1024;  // At least 1K rows
        $maxRows = 10 * 1024 * 1024;  // Max 10M rows to prevent excessive allocation

        return max($minRows, min($rowCount, $maxRows));
    }

    /**
     * Get available system memory in bytes
     *
     * @return int Available memory in bytes
     */
    private static function getAvailableMemory(): int
    {
        // Default to a conservative value if we can't determine
        $defaultMemory = 512 * 1024 * 1024; // 512 MB

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
                    // Use 40% of total as a conservative estimate
                    return (int)($matches[1] * 1024 * 0.4);
                }
            }
        }

        // For other OS, use PHP's memory_limit as a guide
        $memoryLimit = self::parseMemoryLimit();
        if ($memoryLimit > 0) {
            // Use 40% of PHP's memory limit
            return (int)($memoryLimit * 0.4);
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
            return 1024 * 1024 * 1024; // 1 GB
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