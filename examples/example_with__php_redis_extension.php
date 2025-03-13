<?php

/**
 * Example script demonstrating PHP Redis extension with SwooleRedis
 *
 * This example shows how to use the PHP Redis extension to interact
 * with a SwooleRedis server using various Redis commands and data types.
 *
 * Requirements:
 * - SwooleRedis server running with RESP protocol support
 * - PHP Redis extension installed
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
    'timeout' => 2.0,
    'retry_interval' => 100, // ms
    'read_timeout' => 2.0
];

echo "SwooleRedis PHP Extension Example\n";
echo "=================================\n\n";

// Create Redis connection
$redis = new Redis();

// Connection options
echo "Connecting to SwooleRedis server at {$config['host']}:{$config['port']}...\n";
try {
    $connected = $redis->connect(
        $config['host'],
        $config['port'],
        $config['timeout'],
        null,
        $config['retry_interval'],
        $config['read_timeout']
    );

    if (!$connected) {
        die("Failed to connect to SwooleRedis server.\n");
    }

    echo "Connected successfully!\n\n";

    // Example 1: Basic key-value operations
    echo "Example 1: Basic key-value operations\n";
    echo "-------------------------------------\n";

    // Set a string value
    $redis->set('user:name', 'John Doe');
    echo "SET user:name 'John Doe'\n";

    // Get the value
    $name = $redis->get('user:name');
    echo "GET user:name: " . $name . "\n";

    // Set with expiration time (10 seconds)
    $redis->setEx('temporary:key', 10, 'This will expire in 10 seconds');
    echo "SETEX temporary:key 10 'This will expire in 10 seconds'\n";

    // Get TTL
    $ttl = $redis->ttl('temporary:key');
    echo "TTL temporary:key: " . $ttl . " seconds\n";

    // Increment a counter
    $redis->set('counter', 0);
    $redis->incr('counter');
    $redis->incrBy('counter', 5);
    $count = $redis->get('counter');
    echo "Counter value after increments: " . $count . "\n\n";

    // Example 2: Working with lists
    echo "Example 2: Working with lists\n";
    echo "--------------------------\n";

    // Clear any existing list
    $redis->del('users:list');

    // Push items to the list
    $redis->lPush('users:list', 'user1');
    $redis->rPush('users:list', 'user2');
    $redis->rPush('users:list', 'user3');
    echo "Added users to list: user1, user2, user3\n";

    // Get list length
    $length = $redis->lLen('users:list');
    echo "List length: " . $length . "\n";

    // Get range of items
    $users = $redis->lRange('users:list', 0, -1);
    echo "All users in list: " . implode(', ', $users) . "\n";

    // Pop an item from the list
    $firstUser = $redis->lPop('users:list');
    echo "Popped first user: " . $firstUser . "\n";

    // Get updated list
    $users = $redis->lRange('users:list', 0, -1);
    echo "Updated users in list: " . implode(', ', $users) . "\n\n";

    // Example 3: Working with hashes
    echo "Example 3: Working with hashes\n";
    echo "---------------------------\n";

    // Clear any existing hash
    $redis->del('user:profile');

    // Set multiple hash fields
    $redis->hMSet('user:profile', [
        'name' => 'Jane Smith',
        'email' => 'jane@example.com',
        'age' => 28,
        'city' => 'New York'
    ]);
    echo "Created user profile hash\n";

    // Get a single field
    $email = $redis->hGet('user:profile', 'email');
    echo "User email: " . $email . "\n";

    // Get multiple fields
    $nameCity = $redis->hMGet('user:profile', ['name', 'city']);
    echo "User name and city: " . $nameCity['name'] . " from " . $nameCity['city'] . "\n";

    // Get all fields
    $profile = $redis->hGetAll('user:profile');
    echo "Full profile: " . json_encode($profile) . "\n";

    // Check if field exists
    $hasPhone = $redis->hExists('user:profile', 'phone');
    echo "Has phone: " . ($hasPhone ? 'yes' : 'no') . "\n";

    // Add a new field
    $redis->hSet('user:profile', 'phone', '555-1234');
    echo "Added phone number\n";

    // Get all keys
    $fields = $redis->hKeys('user:profile');
    echo "All profile fields: " . implode(', ', $fields) . "\n\n";

    // Example 4: Pipeline commands
    echo "Example 4: Pipeline commands\n";
    echo "--------------------------\n";

    $redis->multi(Redis::PIPELINE);
    $redis->set('pipeline:key1', 'value1');
    $redis->set('pipeline:key2', 'value2');
    $redis->set('pipeline:key3', 'value3');
    $redis->get('pipeline:key1');
    $redis->get('pipeline:key2');
    $redis->get('pipeline:key3');
    $results = $redis->exec();

    echo "Pipeline results:\n";
    print_r($results);
    echo "\n";

    // Example 5: Working with binary data
    echo "Example 5: Working with binary data\n";
    echo "--------------------------------\n";

    // Create some binary data
    $binaryData = '';
    for ($i = 0; $i < 256; $i++) {
        $binaryData .= chr($i);
    }

    // Store the binary data
    $redis->set('binary:data', $binaryData);
    echo "Stored 256 bytes of binary data\n";

    // Retrieve the binary data
    $retrievedData = $redis->get('binary:data');

    // Verify data integrity
    $isMatch = ($binaryData === $retrievedData);
    echo "Binary data integrity check: " . ($isMatch ? 'PASSED' : 'FAILED') . "\n";
    echo "Original data length: " . strlen($binaryData) . " bytes\n";
    echo "Retrieved data length: " . strlen($retrievedData) . " bytes\n\n";

    // Example 6: Publish/Subscribe
    echo "Example 6: Publish example\n";
    echo "------------------------\n";
    echo "Note: To see subscriber functionality, run a separate subscribe script.\n";

    // Publish a message to a channel
    $recipients = $redis->publish('news:channel', 'Breaking news: SwooleRedis supports RESP protocol!');
    echo "Published message to 'news:channel', received by {$recipients} subscribers\n\n";

    // Clean up
    echo "Cleaning up test data...\n";
    $keys = [
        'user:name', 'temporary:key', 'counter', 'users:list',
        'user:profile', 'pipeline:key1', 'pipeline:key2', 'pipeline:key3',
        'binary:data'
    ];
    $redis->del($keys);
    echo "Deleted test keys\n\n";

    // Close connection
    $redis->close();
    echo "Connection closed\n";
    echo "Example completed successfully!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}