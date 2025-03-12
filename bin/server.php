<?php

/**
 * SwooleRedis server startup script with improved signal handling
 */

use Ody\SwooleRedis\Server;

// Load autoloader
require __DIR__ . '/../vendor/autoload.php';

// Basic check for pcntl extension
if (!extension_loaded('pcntl')) {
    echo "Warning: pcntl extension is not loaded.\n";
    echo "Signal handling (Ctrl+C) may not work correctly.\n";
    echo "You may need to use 'kill' to stop the server process.\n\n";
}

// Parse command line arguments
$shortOptions = 'h:p:d:';
$longOptions = [
    'host:', 'port:', 'dir:',
    'rdb-enabled::', 'rdb-filename:', 'rdb-save-seconds:', 'rdb-min-changes:',
    'aof-enabled::', 'aof-filename:', 'aof-fsync:', 'aof-rewrite-seconds:', 'aof-rewrite-min-size:'
];

$options = getopt($shortOptions, $longOptions);

$host = $options['h'] ?? $options['host'] ?? '127.0.0.1';
$port = (int)($options['p'] ?? $options['port'] ?? 6380);

// Build configuration array
$config = [
    'persistence_dir' => $options['d'] ?? $options['dir'] ?? '/tmp',
];

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
echo "SwooleRedis Server v1.0.0\n";
echo "=========================\n";
echo "Server will listen on: {$host}:{$port}\n";
echo "Persistence directory: {$config['persistence_dir']}\n";
echo "\n";
echo "RDB Persistence: " . ($config['rdb_enabled'] ?? true ? "Enabled" : "Disabled") . "\n";
echo "AOF Persistence: " . ($config['aof_enabled'] ?? false ? "Enabled" : "Disabled") . "\n";
echo "\n";
echo "How to stop the server:\n";
echo "1. Press Ctrl+C in this terminal\n";
echo "2. Use 'SHUTDOWN' command from a Redis client\n";
echo "   Example: redis-cli -p {$port} SHUTDOWN\n";
echo "3. Use 'kill " . getmypid() . "' from another terminal\n";
echo "\n";
echo "Starting server...\n";

// Create and start the server
$r = new Server($host, $port, $config);

// Start the server - signal handling is now inside the Server class
$r->start();