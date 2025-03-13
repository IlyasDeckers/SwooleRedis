<?php

/**
 * SwooleRedis server startup script with improved signal handling,
 * RESP protocol support and enhanced data structures
 */

use Ody\SwooleRedis\Server;
use Ody\SwooleRedis\DebugLogger;

// Load autoloader
require __DIR__ . '/../vendor/autoload.php';

// Basic check for pcntl extension
if (!extension_loaded('pcntl')) {
    echo "Warning: pcntl extension is not loaded.\n";
    echo "Signal handling (Ctrl+C) may not work correctly.\n";
    echo "You may need to use 'kill' to stop the server process.\n\n";
}

// Parse command line arguments
$shortOptions = 'h:p:d:m:';
$longOptions = [
    'host:', 'port:', 'dir:', 'memory-limit:',
    'memory-string-table-size:', 'memory-hash-table-size:',
    'memory-list-table-size:', 'memory-set-table-size:',
    'memory-zset-table-size:', 'memory-expiry-table-size:',
    'rdb-enabled::', 'rdb-filename:', 'rdb-save-seconds:', 'rdb-min-changes:',
    'aof-enabled::', 'aof-filename:', 'aof-fsync:', 'aof-rewrite-seconds:', 'aof-rewrite-min-size:',
    'worker-num:', 'max-conn:', 'backlog:',
    'debug::', 'debug-log-file:', 'debug-components:', 'debug-max-log-size:'
];

$options = getopt($shortOptions, $longOptions);

$host = $options['h'] ?? $options['host'] ?? '127.0.0.1';
$port = (int)($options['p'] ?? $options['port'] ?? 6380);

// Build configuration array
$config = [
    'persistence_dir' => $options['d'] ?? $options['dir'] ?? '/tmp',
];

// Memory configuration
if (isset($options['m']) || isset($options['memory-limit'])) {
    $memoryLimit = $options['m'] ?? $options['memory-limit'];

    // Parse memory limit value (e.g., 512M, 1G)
    $value = (int)$memoryLimit;
    $suffix = strtoupper(substr($memoryLimit, -1));

    if ($suffix === 'K') {
        $value *= 1024;
    } elseif ($suffix === 'M') {
        $value *= 1024 * 1024;
    } elseif ($suffix === 'G') {
        $value *= 1024 * 1024 * 1024;
    }

    ini_set('memory_limit', $memoryLimit);
    echo "PHP memory limit set to: $memoryLimit\n";
}

// Table size configuration
if (isset($options['memory-string-table-size'])) {
    $config['memory_string_table_size'] = (int)$options['memory-string-table-size'];
}
if (isset($options['memory-hash-table-size'])) {
    $config['memory_hash_table_size'] = (int)$options['memory-hash-table-size'];
}
if (isset($options['memory-list-table-size'])) {
    $config['memory_list_table_size'] = (int)$options['memory-list-table-size'];
}
if (isset($options['memory-set-table-size'])) {
    $config['memory_set_table_size'] = (int)$options['memory-set-table-size'];
}
if (isset($options['memory-zset-table-size'])) {
    $config['memory_zset_table_size'] = (int)$options['memory-zset-table-size'];
}
if (isset($options['memory-expiry-table-size'])) {
    $config['memory_expiry_table_size'] = (int)$options['memory-expiry-table-size'];
}

// RDB configuration
if (isset($options['rdb-enabled'])) {
    $config['rdb_enabled'] = filter_var($options['rdb-enabled'], FILTER_VALIDATE_BOOLEAN);
}
if (isset($options['rdb-filename'])) {
    $config['rdb_filename'] = $options['rdb-filename'];
}
if (isset($options['rdb-save-seconds'])) {
    $config['rdb_save_seconds'] = (int)$options['rdb-save-seconds'];
}
if (isset($options['rdb-min-changes'])) {
    $config['rdb_min_changes'] = (int)$options['rdb-min-changes'];
}

// AOF configuration
if (isset($options['aof-enabled'])) {
    $config['aof_enabled'] = filter_var($options['aof-enabled'], FILTER_VALIDATE_BOOLEAN);
}
if (isset($options['aof-filename'])) {
    $config['aof_filename'] = $options['aof-filename'];
}
if (isset($options['aof-fsync'])) {
    $fsync = $options['aof-fsync'];
    if (in_array($fsync, ['always', 'everysec', 'no'])) {
        $config['aof_fsync'] = $fsync;
    }
}
if (isset($options['aof-rewrite-seconds'])) {
    $config['aof_rewrite_seconds'] = (int)$options['aof-rewrite-seconds'];
}
if (isset($options['aof-rewrite-min-size'])) {
    $config['aof_rewrite_min_size'] = (int)$options['aof-rewrite-min-size'];
}

// Swoole server configuration
if (isset($options['worker-num'])) {
    $config['worker_num'] = (int)$options['worker-num'];
}
if (isset($options['max-conn'])) {
    $config['max_conn'] = (int)$options['max-conn'];
}
if (isset($options['backlog'])) {
    $config['backlog'] = (int)$options['backlog'];
}

// Debug configuration
$debugConfig = [
    'debug_enabled' => isset($options['debug']) ?
        filter_var($options['debug'], FILTER_VALIDATE_BOOLEAN) : false,
    'debug_log_file' => $options['debug-log-file'] ?? '/tmp/swoole_redis_debug.log',
    'debug_max_log_size' => isset($options['debug-max-log-size']) ?
        (int)$options['debug-max-log-size'] : 10 * 1024 * 1024
];

if (isset($options['debug-components'])) {
    $debugConfig['debug_components'] = explode(',', $options['debug-components']);
}

// Initialize debug logger
DebugLogger::init($debugConfig);

// Add debug configuration to server config
$config['debug_enabled'] = $debugConfig['debug_enabled'];

// ASCII art logo for a more professional look
echo <<<'EOT'
  _____                    _      _____          _ _     
 / ____|                  | |    |  __ \        | (_)    
| (_____      _____   ___ | | ___| |__) |___  __| |_ ___ 
 \___ \ \ /\ / / _ \ / _ \| |/ _ \  _  // _ \/ _` | / __|
 ____) \ V  V / (_) | (_) | |  __/ | \ \  __/ (_| | \__ \
|_____/ \_/\_/ \___/ \___/|_|\___|_|  \_\___|\__,_|_|___/
                                                         
EOT;

echo "\n";
echo "SwooleRedis Server v1.1.0 with Enhanced Data Structures\n";
echo "====================================================\n";
echo "Server will listen on: {$host}:{$port}\n";
echo "Persistence directory: {$config['persistence_dir']}\n";
echo "\n";
echo "Memory: Using dynamic allocation based on system resources\n";

// Display memory-related configuration if explicitly set
if (isset($config['memory_string_table_size'])) {
    echo "String table size: " . $config['memory_string_table_size'] . " entries\n";
}
if (isset($config['memory_hash_table_size'])) {
    echo "Hash table size: " . $config['memory_hash_table_size'] . " entries\n";
}
if (isset($config['memory_list_table_size'])) {
    echo "List meta table size: " . $config['memory_list_table_size'] . " entries\n";
}
if (isset($config['memory_set_table_size'])) {
    echo "Set table size: " . $config['memory_set_table_size'] . " entries\n";
}
if (isset($config['memory_zset_table_size'])) {
    echo "Sorted Set table size: " . $config['memory_zset_table_size'] . " entries\n";
}
if (isset($config['memory_expiry_table_size'])) {
    echo "Expiry table size: " . $config['memory_expiry_table_size'] . " entries\n";
}

echo "\n";
echo "RDB Persistence: " . ($config['rdb_enabled'] ?? true ? "Enabled" : "Disabled") . "\n";
echo "AOF Persistence: " . ($config['aof_enabled'] ?? false ? "Enabled" : "Disabled") . "\n";
echo "RESP Protocol Support: Enabled\n";
echo "Debug Mode: " . ($debugConfig['debug_enabled'] ? "Enabled" : "Disabled") . "\n";
if ($debugConfig['debug_enabled']) {
    echo "Debug Log: " . $debugConfig['debug_log_file'] . "\n";
    echo "Debug Components: " . implode(', ', $debugConfig['debug_components'] ?? ['all']) . "\n";
}

echo "\n";
echo "Enhanced Data Structures: Enabled\n";
echo "- Sets (SADD, SMEMBERS, etc.)\n";
echo "- Sorted Sets (ZADD, ZRANGE, etc.)\n";
echo "- Bitmaps (SETBIT, GETBIT, etc.)\n";
echo "- HyperLogLogs (PFADD, PFCOUNT, etc.)\n";
echo "- Transactions (MULTI, EXEC, etc.)\n";

echo "\n";
echo "How to stop the server:\n";
echo "1. Press Ctrl+C in this terminal\n";
echo "2. Use 'SHUTDOWN' command from a Redis client\n";
echo "   Example: redis-cli -p {$port} SHUTDOWN\n";
echo "3. Use 'kill " . getmypid() . "' from another terminal\n";
echo "\n";
echo "Memory configuration:\n";
echo "For low memory environments, use '--memory-limit=128M' to set PHP memory limit\n";
echo "For more specific control, use:\n";
echo "  --memory-string-table-size=1024     (for string table)\n";
echo "  --memory-hash-table-size=1024       (for hash table)\n";
echo "  --memory-list-table-size=1024       (for list table)\n";
echo "  --memory-set-table-size=1024        (for set table)\n";
echo "  --memory-zset-table-size=1024       (for sorted set table)\n";
echo "  --memory-expiry-table-size=1024     (for expiry table)\n";
echo "\n";
echo "Debug configuration:\n";
echo "  --debug=true                        (enable debug logging)\n";
echo "  --debug-log-file=/path/to/log       (set debug log file)\n";
echo "  --debug-components=resp,server      (comma-separated list of components to debug)\n";
echo "  --debug-max-log-size=10485760       (maximum log file size in bytes)\n";
echo "\n";
echo "Starting server...\n";

if ($debugConfig['debug_enabled']) {
    DebugLogger::log('system', 'Server starting', ['host' => $host, 'port' => $port]);
}

// Create and start the server
$r = new Server($host, $port, $config);

// Start the server - signal handling is now inside the Server class
$r->start();