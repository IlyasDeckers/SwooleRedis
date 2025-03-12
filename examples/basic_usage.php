<?php

/**
 * Example usage of the SwooleRedis client
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

// Test string operations
echo "\n== String Operations ==\n";
$response = $client->command("PING");
echo "PING: " . $response . "\n";

$response = $client->set('test', '123');
echo "SET test 123: " . $response . "\n";

$response = $client->get('test');
echo "GET test: " . $response . "\n";

// Test expiration
echo "\n== Expiration ==\n";
echo "EXPIRE test 5: " . $client->expire('test', 5) . "\n";
echo "TTL test: " . $client->ttl('test') . "\n";
echo "Waiting for key to expire...\n";
sleep(6);
echo "GET test after expiry: " . $client->get('test') . "\n";

// Test list operations
echo "\n== List Operations ==\n";
echo "LPUSH mylist first: " . $client->lPush('mylist', 'first') . "\n";
echo "LPUSH mylist second: " . $client->lPush('mylist', 'second') . "\n";
echo "RPUSH mylist third: " . $client->rPush('mylist', 'third') . "\n";
echo "LPOP mylist: " . $client->lPop('mylist') . "\n";
echo "RPOP mylist: " . $client->rPop('mylist') . "\n";

// Test hash operations
echo "\n== Hash Operations ==\n";
echo "HSET user:1 name John: " . $client->hSet('user:1', 'name', 'John') . "\n";
echo "HSET user:1 email john@example.com: " . $client->hSet('user:1', 'email', 'john@example.com') . "\n";
echo "HGET user:1 name: " . $client->hGet('user:1', 'name') . "\n";
echo "HGET user:1 email: " . $client->hGet('user:1', 'email') . "\n";
echo "HDEL user:1 email: " . $client->hDel('user:1', 'email') . "\n";
echo "HGET user:1 email after delete: " . $client->hGet('user:1', 'email') . "\n";

// Close the connection
$client->close();
echo "\nConnection closed\n";