<?php

/**
 * Example usage demonstrating the enhanced data structures in SwooleRedis
 * This version uses smaller data sets to be more memory-efficient
 */

use Ody\SwooleRedis\Client\Client;

// Load autoloader
require __DIR__ . '/../vendor/autoload.php';

// Create a client instance
$client = new Client('127.0.0.1', 6380);

// Connect to the server
if (!$client->connect()) {
    echo "Failed to connect to SwooleRedis server. Is it running?\n";
    exit(1);
}

echo "Connected to SwooleRedis server\n";

// Helper function to display section headers
function section($title) {
    echo "\n" . str_repeat('=', 80) . "\n";
    echo "  " . strtoupper($title) . "\n";
    echo str_repeat('=', 80) . "\n\n";
}

// Helper function to display commands and their results
function cmd($client, $command) {
    echo "> " . $command . "\n";
    $result = $client->command($command);
    echo $result . "\n";
    return $result;
}

// Test Set operations
section("Set Operations");

cmd($client, "DEL myset myset2 myset3");
cmd($client, "SADD myset apple banana cherry date");
cmd($client, "SADD myset2 banana date fig grape");
cmd($client, "SCARD myset");
cmd($client, "SMEMBERS myset");
cmd($client, "SISMEMBER myset apple");
cmd($client, "SISMEMBER myset fig");
cmd($client, "SMOVE myset myset3 banana");
cmd($client, "SMEMBERS myset");
cmd($client, "SMEMBERS myset3");
cmd($client, "SINTER myset myset2");
cmd($client, "SUNION myset myset2");
cmd($client, "SDIFF myset myset2");
cmd($client, "SRANDMEMBER myset");
cmd($client, "SRANDMEMBER myset 2");
cmd($client, "SPOP myset");
cmd($client, "SMEMBERS myset");

// Test Sorted Set operations
section("Sorted Set Operations");

cmd($client, "DEL leaderboard");
cmd($client, "ZADD leaderboard 100 player1");
cmd($client, "ZADD leaderboard 75 player2");
cmd($client, "ZADD leaderboard 150 player3");
cmd($client, "ZADD leaderboard 50 player4");
cmd($client, "ZCARD leaderboard");
cmd($client, "ZSCORE leaderboard player3");
cmd($client, "ZRANGE leaderboard 0 -1");
cmd($client, "ZRANGE leaderboard 0 -1 WITHSCORES");
cmd($client, "ZREVRANGE leaderboard 0 -1 WITHSCORES");
cmd($client, "ZRANGEBYSCORE leaderboard 70 120 WITHSCORES");
cmd($client, "ZCOUNT leaderboard 70 120");
cmd($client, "ZINCRBY leaderboard 25 player4");
cmd($client, "ZSCORE leaderboard player4");
cmd($client, "ZREM leaderboard player2");
cmd($client, "ZRANGE leaderboard 0 -1 WITHSCORES");

// Test Bitmap operations
section("Bitmap Operations");

cmd($client, "DEL bitmap1 bitmap2 bitmap3");
cmd($client, "SETBIT bitmap1 0 1");
cmd($client, "SETBIT bitmap1 3 1");
cmd($client, "SETBIT bitmap1 5 1");
cmd($client, "GETBIT bitmap1 0");
cmd($client, "GETBIT bitmap1 1");
cmd($client, "GETBIT bitmap1 3");
cmd($client, "BITCOUNT bitmap1");

// Let's create a pattern in bitmap2
for ($i = 0; $i < 16; $i++) {
    if ($i % 2 == 0) {
        cmd($client, "SETBIT bitmap2 $i 1");
    }
}

cmd($client, "BITCOUNT bitmap2");
cmd($client, "BITOP AND bitmap3 bitmap1 bitmap2");
cmd($client, "BITCOUNT bitmap3");
cmd($client, "BITOP OR bitmap3 bitmap1 bitmap2");
cmd($client, "BITCOUNT bitmap3");
cmd($client, "BITPOS bitmap1 1");
cmd($client, "BITPOS bitmap1 0");

// Test HyperLogLog operations - further reduced data size
section("HyperLogLog Operations");

cmd($client, "DEL hll1 hll2 hll3 hll-merged");

// Add some values to HyperLogLog counters - much reduced count
echo "Adding elements to HyperLogLog (this is slow, please wait)...\n";
for ($i = 0; $i < 50; $i++) {  // Only 50 values
    cmd($client, "PFADD hll1 user{$i}");

    if ($i % 2 == 0) {
        cmd($client, "PFADD hll2 user{$i}");
    }

    if ($i >= 25) {
        cmd($client, "PFADD hll3 user{$i}");
    }
}

echo "\nTesting HyperLogLog counts:\n";
cmd($client, "PFCOUNT hll1");
cmd($client, "PFCOUNT hll2");
cmd($client, "PFCOUNT hll3");
cmd($client, "PFCOUNT hll1 hll2");
cmd($client, "PFCOUNT hll1 hll3");
cmd($client, "PFMERGE hll-merged hll1 hll2");
cmd($client, "PFCOUNT hll-merged");

// Test Transactions
section("Transactions");

cmd($client, "DEL txn-key1 txn-key2");
cmd($client, "SET txn-key1 initial-value");

// Start a transaction
cmd($client, "MULTI");
cmd($client, "SET txn-key1 new-value");
cmd($client, "SET txn-key2 another-value");
cmd($client, "INCR txn-counter");
cmd($client, "GET txn-key1");
cmd($client, "GET txn-key2");

// Execute the transaction
$txnResult = cmd($client, "EXEC");
echo "Transaction executed.\n";

cmd($client, "GET txn-key1");
cmd($client, "GET txn-key2");

// Test transaction discard
cmd($client, "MULTI");
cmd($client, "SET txn-key1 this-will-not-happen");
cmd($client, "DISCARD");
cmd($client, "GET txn-key1");

// Cleanup
section("Cleanup");

$keys = [
    "myset", "myset2", "myset3",
    "leaderboard",
    "bitmap1", "bitmap2", "bitmap3",
    "hll1", "hll2", "hll3", "hll-merged",
    "txn-key1", "txn-key2", "txn-counter"
];

cmd($client, "DEL " . implode(" ", $keys));
echo "All test keys removed.\n";

// Close the connection
$client->close();
echo "\nConnection closed. Example completed.\n";