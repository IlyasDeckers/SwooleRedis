<?php

/**
 * Example script demonstrating Redis PubSub subscriber with SwooleRedis
 *
 * This example shows how to subscribe to Redis channels using the PHP Redis
 * extension to receive messages from a SwooleRedis server.
 *
 * Requirements:
 * - SwooleRedis server running with RESP protocol support
 * - PHP Redis extension installed
 *
 * Run this in a separate terminal and use the main example to publish messages.
 */

// Check if Redis extension is installed
if (!extension_loaded('redis')) {
    die("PHP Redis extension not installed. Please install it with:\n" .
        "sudo apt-get install php-redis (Debian/Ubuntu)\n" .
        "or\n" .
        "sudo pecl install redis (using PECL)\n");
}

// Configuration
$config = [
    'host' => '127.0.0.1',
    'port' => 6380,
    'timeout' => 2.0
];

echo "SwooleRedis PubSub Subscriber Example\n";
echo "====================================\n\n";

// Create Redis connection
$redis = new Redis();

// Connect to Redis
echo "Connecting to SwooleRedis server at {$config['host']}:{$config['port']}...\n";
try {
    $connected = $redis->connect($config['host'], $config['port'], $config['timeout']);

    if (!$connected) {
        die("Failed to connect to SwooleRedis server.\n");
    }

    echo "Connected successfully!\n\n";

    // Define channels to subscribe to
    $channels = ['news:channel', 'system:alerts'];

    echo "Subscribing to channels: " . implode(', ', $channels) . "\n";
    echo "Waiting for messages... (Press Ctrl+C to exit)\n\n";

    // Subscribe callback function
    $callback = function ($redis, $channel, $message) {
        $time = date('H:i:s');
        echo "[$time] Message received on channel '$channel': $message\n";
    };

    // Subscribe to channels
    $redis->subscribe($channels, $callback);

    // Note: The script will block at the subscribe call until it's interrupted

    // This code will only execute if the subscription is cancelled
    echo "\nSubscription ended\n";
    $redis->close();
    echo "Connection closed\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}