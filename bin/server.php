<?php

/**
 * SwooleRedis server startup script
 */

use Ody\SwooleRedis\Server;

// Load autoloader
require __DIR__ . '/../vendor/autoload.php';

// Parse command line arguments
$options = getopt('h:p:', ['host:', 'port:']);

$host = $options['h'] ?? $options['host'] ?? '127.0.0.1';
$port = (int)($options['p'] ?? $options['port'] ?? 6380);

// Create and start the server
$server = new Server($host, $port);
$server->start();