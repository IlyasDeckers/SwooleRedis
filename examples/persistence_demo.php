<?php

/**
 * Example demonstrating persistence features in SwooleRedis
 */

use Ody\SwooleRedis\Client\Client;

// Load autoloader
require __DIR__ . '/../vendor/autoload.php';

// Create a client instance
$client = new Client('127.0.0.1', 6380);

// Connect to the server
if (!$client->connect()) {
    exit(1);
}

echo "Connected to SwooleRedis server\n";

// Step 1: Check server info
echo "\n== Server Info ==\n";
$info = $client->command("INFO persistence");
echo "INFO persistence:\n$info\n";

// Step 2: Set some data to persist
echo "\n== Setting test data ==\n";
$client->set('persistent_string', 'This value will persist through server restarts');
echo "SET persistent_string: " . $client->command("SET persistent_string 'This value will persist through server restarts'") . "\n";

// Add a hash
echo "HSET user:persistent name John: " . $client->command("HSET user:persistent name John") . "\n";
echo "HSET user:persistent email john@example.com: " . $client->command("HSET user:persistent email john@example.com") . "\n";

// Add a list
echo "RPUSH persistent_list item1: " . $client->command("RPUSH persistent_list item1") . "\n";
echo "RPUSH persistent_list item2: " . $client->command("RPUSH persistent_list item2") . "\n";
echo "RPUSH persistent_list item3: " . $client->command("RPUSH persistent_list item3") . "\n";

// Set expiry on a key to verify expiry persistence
echo "EXPIRE user:persistent 3600: " . $client->command("EXPIRE user:persistent 3600") . "\n";

// Step 3: Trigger a manual save
echo "\n== Triggering manual save ==\n";
echo "SAVE: " . $client->command("SAVE") . "\n";

// Verify LASTSAVE updated
echo "LASTSAVE: " . $client->command("LASTSAVE") . "\n";

// Step 4: Display instructions
echo "\n== Next steps ==\n";
echo "1. Stop and restart the SwooleRedis server\n";
echo "2. Run this script again to verify data persistence\n";
echo "3. Try changing AOF and RDB settings to see how they affect persistence\n";

// Step 5: Check if this is a verification run by seeing if our test data already exists
echo "\n== Checking for existing data ==\n";
$value = $client->get('persistent_string');
echo "GET persistent_string: " . $value . "\n";

if (strpos($value, "This value will persist") !== false) {
    echo "\n✅ SUCCESS: Data was successfully persisted and loaded!\n";

    // Show all persisted data
    echo "\n== Persisted Data ==\n";
    echo "HGET user:persistent name: " . $client->command("HGET user:persistent name") . "\n";
    echo "TTL user:persistent: " . $client->command("TTL user:persistent") . "\n";
    echo "LRANGE persistent_list 0 -1: " . $client->command("LRANGE persistent_list 0 -1") . "\n";
}

// Close the connection
$client->close();
echo "\nConnection closed\n";