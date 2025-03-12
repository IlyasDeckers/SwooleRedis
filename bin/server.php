<?php

/**
 * SwooleRedis server startup script
 */

use Ody\SwooleRedis\Server;

// Load autoloader
require __DIR__ . '/../vendor/autoload.php';

// Parse command line arguments
$options = getopt('h:p:', ['host:', 'port:']);
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

// Create and start the server with configuration
$server = new Server($host, $port, $config);

// Add signal handling for graceful shutdown
pcntl_signal(SIGTERM, function () use ($server) {
    echo "Received SIGTERM, shutting down...\n";
    $server->stop();
    exit(0);
});

pcntl_signal(SIGINT, function () use ($server) {
    echo "Received SIGINT, shutting down...\n";
    $server->stop();
    exit(0);
});

// Start the server
$server->start();