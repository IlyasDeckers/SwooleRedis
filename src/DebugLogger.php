<?php

namespace Ody\SwooleRedis;

/**
 * Debug helper for SwooleRedis server
 */
class DebugLogger
{
    private static bool $debugEnabled = false;
    private static string $logFile = '/tmp/swoole_redis_debug.log';
    private static array $debugComponents = [];
    private static int $maxLogSize = 10 * 1024 * 1024; // 10MB

    /**
     * Initialize the debug logger
     *
     * @param array $config Debug configuration
     */
    public static function init(array $config = []): void
    {
        self::$debugEnabled = $config['debug_enabled'] ?? false;
        self::$logFile = $config['debug_log_file'] ?? '/tmp/swoole_redis_debug.log';
        self::$debugComponents = $config['debug_components'] ?? ['server', 'resp', 'command', 'persistence'];
        self::$maxLogSize = $config['debug_max_log_size'] ?? 10 * 1024 * 1024;

        if (self::$debugEnabled) {
            self::rotateLogIfNeeded();
            self::log('system', 'Debug logging initialized');
        }
    }

    /**
     * Check if debugging is enabled for a component
     *
     * @param string $component Component name
     * @return bool True if debugging is enabled
     */
    public static function isEnabled(string $component = 'all'): bool
    {
        if (!self::$debugEnabled) {
            return false;
        }

        if ($component === 'all') {
            return true;
        }

        return in_array($component, self::$debugComponents);
    }

    /**
     * Log a debug message
     *
     * @param string $component Component name (server, resp, command, etc.)
     * @param string $message Message to log
     * @param array $context Additional context data
     */
    public static function log(string $component, string $message, array $context = []): void
    {
        if (!self::isEnabled($component)) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $pid = getmypid();

        $logEntry = "[$timestamp] [$pid] [$component] $message";

        if (!empty($context)) {
            $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES);
            $logEntry .= " Context: $contextJson";
        }

        $logEntry .= PHP_EOL;

        file_put_contents(self::$logFile, $logEntry, FILE_APPEND);
    }

    /**
     * Log binary data
     *
     * @param string $component Component name
     * @param string $message Message prefix
     * @param string $data Binary data to log
     */
    public static function logBinary(string $component, string $message, string $data): void
    {
        if (!self::isEnabled($component)) {
            return;
        }

        $hexData = bin2hex($data);
        $displayData = preg_replace('/([0-9a-f]{2})/', '$1 ', $hexData);

        self::log($component, $message . " (HEX): " . $displayData);
    }

    /**
     * Rotate log file if it exceeds maximum size
     */
    private static function rotateLogIfNeeded(): void
    {
        if (!file_exists(self::$logFile)) {
            return;
        }

        $size = filesize(self::$logFile);

        if ($size >= self::$maxLogSize) {
            $backupFile = self::$logFile . '.' . date('YmdHis');
            rename(self::$logFile, $backupFile);
        }
    }
}