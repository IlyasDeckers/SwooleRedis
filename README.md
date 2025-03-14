# SwooleRedis

SwooleRedis is a Redis-like server implementation using Swoole's high-performance asynchronous event-driven programming framework for PHP. This project demonstrates how to use Swoole Tables (shared memory) to build an in-memory data store similar to Redis.

## Features

- **Key-Value Operations**: Basic string storage with SET, GET, DEL commands
- **Expiration**: Support for EXPIRE and TTL commands
- **Data Structures**:
    - **Lists**: LPUSH, RPUSH, LPOP, RPOP operations
    - **Hashes**: HSET, HGET, HDEL operations
    - **Sets**: SADD, SCARD, SMEMBERS, SINTER, SUNION, SDIFF operations
    - **Sorted Sets**: ZADD, ZRANGE, ZRANGEBYSCORE, ZREM operations
    - **Bitmaps**: GETBIT, SETBIT, BITCOUNT, BITOP, BITPOS operations
    - **HyperLogLog**: PFADD, PFCOUNT, PFMERGE operations
- **Transactions**: MULTI, EXEC, DISCARD, WATCH support
- **Pub/Sub Messaging**: SUBSCRIBE and PUBLISH commands
- **High Performance**: Uses Swoole's shared memory tables for low-latency operations
- **Concurrency**: Multi-process architecture supports high concurrency
- **Persistence**: RDB snapshots and AOF log support

## Requirements

- PHP 8.3 or higher
- Swoole extension +6.0

## Installation

1. Clone the repository:
```
git clone https://github.com/IlyasDeckers/swoole-redis.git
cd swoole-redis
```

2. Install dependencies:
```
composer install
```

## Usage

### Starting the server

```
php bin/server.php
```

Options:
- `-h, --host`: The host to bind to (default: 127.0.0.1)
- `-p, --port`: The port to listen on (default: 6380)

### Example client usage

```php
<?php
use Ody\SwooleRedis\Client\Client;

$client = new Client('127.0.0.1', 6380);
$client->connect();

// String operations
$client->set('key', 'value');
$client->get('key');

// Expiration
$client->expire('key', 60); // 60 seconds
$client->ttl('key');

// List operations
$client->lPush('mylist', 'value1', 'value2');
$client->lPop('mylist');

// Hash operations
$client->hSet('myhash', 'field', 'value');
$client->hGet('myhash', 'field');

// Set operations
$client->command("SADD myset apple banana cherry");
$client->command("SMEMBERS myset");

// Sorted Set operations
$client->command("ZADD leaderboard 100 player1 75 player2");
$client->command("ZRANGE leaderboard 0 -1 WITHSCORES");

// Bitmap operations
$client->command("SETBIT bitmap1 5 1");
$client->command("GETBIT bitmap1 5");

// HyperLogLog operations
$client->command("PFADD visitors user1 user2 user3");
$client->command("PFCOUNT visitors");

// Transaction operations
$client->command("MULTI");
$client->command("SET key1 value1");
$client->command("SET key2 value2");
$client->command("EXEC");

$client->close();
```

See the `examples` directory for more detailed examples.

## Enhanced Data Structures

### Sets

Sets are unordered collections of unique strings, useful for membership tests and operations like union, intersection, and difference.

```php
// Adding elements
$client->command("SADD myset apple banana cherry");

// Getting all members
$client->command("SMEMBERS myset");

// Checking membership
$client->command("SISMEMBER myset apple"); // 1
$client->command("SISMEMBER myset orange"); // 0

// Set operations
$client->command("SINTER set1 set2"); // Intersection
$client->command("SUNION set1 set2"); // Union
$client->command("SDIFF set1 set2");  // Difference

// Other operations
$client->command("SCARD myset");      // Count members
$client->command("SRANDMEMBER myset"); // Random member
$client->command("SPOP myset");       // Remove and return random member
```

### Sorted Sets

Sorted sets associate a score with each member, enabling ordered operations and range queries.

```php
// Adding members with scores
$client->command("ZADD leaderboard 100 player1 75 player2 150 player3");

// Querying by rank (position)
$client->command("ZRANGE leaderboard 0 -1 WITHSCORES"); // All members with scores
$client->command("ZREVRANGE leaderboard 0 -1 WITHSCORES"); // Descending order

// Querying by score
$client->command("ZRANGEBYSCORE leaderboard 70 120 WITHSCORES");
$client->command("ZCOUNT leaderboard 70 120"); // Count in score range

// Modifying scores
$client->command("ZINCRBY leaderboard 25 player2"); // Increment score

// Other operations
$client->command("ZSCORE leaderboard player1"); // Get score
$client->command("ZCARD leaderboard"); // Count members
$client->command("ZREM leaderboard player2"); // Remove member
```

### Bitmaps

Bitmaps are strings treated as bit arrays, enabling efficient bit operations and counting.

```php
// Setting and getting bits
$client->command("SETBIT bitmap1 5 1"); // Set bit at offset 5 to 1
$client->command("GETBIT bitmap1 5"); // Get bit at offset 5

// Bit operations
$client->command("BITCOUNT bitmap1"); // Count set bits
$client->command("BITOP AND result bitmap1 bitmap2"); // Bitwise AND
$client->command("BITOP OR result bitmap1 bitmap2"); // Bitwise OR
$client->command("BITOP XOR result bitmap1 bitmap2"); // Bitwise XOR
$client->command("BITOP NOT result bitmap1"); // Bitwise NOT

// Position operations
$client->command("BITPOS bitmap1 1"); // Position of first set bit
$client->command("BITPOS bitmap1 0"); // Position of first unset bit
```

### HyperLogLog

HyperLogLog is a probabilistic data structure for cardinality estimation with minimal memory usage.

```php
// Adding elements
$client->command("PFADD visitors user1 user2 user3");

// Counting unique elements
$client->command("PFCOUNT visitors");

// Merging HyperLogLogs
$client->command("PFMERGE result visitors1 visitors2");
```

## Transaction Support

SwooleRedis now includes transaction support, allowing multiple commands to be executed atomically.

```php
// Start a transaction
$client->command("MULTI");

// Queue commands (returns QUEUED for each command)
$client->command("SET key1 value1");
$client->command("SET key2 value2");
$client->command("INCR counter");

// Execute all commands atomically
$client->command("EXEC");

// Discard a transaction
$client->command("MULTI");
$client->command("SET key1 value");
$client->command("DISCARD"); // Transaction is cancelled

// Optimistic locking with WATCH
$client->command("WATCH key1");
$client->command("MULTI");
$client->command("SET key1 newvalue");
$client->command("EXEC"); // Succeeds only if key1 wasn't modified
```

## Data Persistence

SwooleRedis supports data persistence similar to Redis with two complementary methods:

### RDB (Redis Database)

RDB persistence performs point-in-time snapshots of your dataset at specified intervals. This is useful for:

- Backups
- Disaster recovery
- Data migration

The RDB file is compact and ideal for backups. It's the default persistence method.

### AOF (Append Only File)

AOF persistence logs every write operation received by the server. It can be replayed on server restart to reconstruct the original dataset. This provides:

- Better durability (minimize data loss)
- Safer operations (every change is logged)
- Easier debugging (see all operations)

AOF files are typically larger than RDB files but provide better durability.

### Configuration Options

You can configure persistence when starting the server:

```bash
# Enable RDB with custom settings
php bin/server.php --rdb-enabled=true --rdb-filename=dump.rdb --rdb-save-seconds=900 --rdb-min-changes=10

# Enable AOF with custom settings
php bin/server.php --aof-enabled=true --aof-filename=appendonly.aof --aof-fsync=everysec

# Enable both persistence methods
php bin/server.php --rdb-enabled=true --aof-enabled=true --dir=/path/to/data
```

Available options:

| Option | Description | Default |
|--------|-------------|---------|
| `--dir` | Directory where persistence files are stored | `/tmp` |
| `--rdb-enabled` | Enable RDB persistence | `true` |
| `--rdb-filename` | RDB filename | `dump.rdb` |
| `--rdb-save-seconds` | Save interval in seconds | `900` (15 min) |
| `--rdb-min-changes` | Minimum number of changes before saving | `1` |
| `--aof-enabled` | Enable AOF persistence | `false` |
| `--aof-filename` | AOF filename | `appendonly.aof` |
| `--aof-fsync` | Fsync strategy (always, everysec, no) | `everysec` |
| `--aof-rewrite-seconds` | AOF rewrite interval in seconds | `3600` (1 hour) |
| `--aof-rewrite-min-size` | Minimum AOF file size before rewrite | `67108864` (64MB) |

### Administration Commands

SwooleRedis adds several server administration commands to manage persistence:

- `SAVE` - Force a synchronous save
- `BGSAVE` - Start a background save
- `LASTSAVE` - Get the timestamp of the last successful save
- `INFO` - Get information about the server, including persistence stats

Example:

```
> SAVE
OK
> INFO persistence
# persistence
rdb_changes_since_last_save:0
rdb_last_save_time:1627984501
rdb_last_save_status:ok
aof_enabled:1
aof_rewrite_in_progress:0
aof_last_rewrite_time_sec:-1
aof_current_size:1024
aof_pending_rewrite:0
```

## Memory Management

SwooleRedis dynamically allocates memory based on your system's available resources. This ensures optimal performance across different hardware configurations, from small VPS instances to large dedicated servers.

### Dynamic Memory Allocation

- **Automatic Sizing**: By default, SwooleRedis detects your system's available memory and allocates appropriate amounts for different data structures.
- **Intelligent Distribution**: Memory is distributed proportionally among different storage types (strings, hashes, lists, etc.) based on typical usage patterns.
- **Resource Protection**: The system avoids over-allocation to prevent out-of-memory errors and system instability.

### Memory Configuration Options

You can override the automatic memory allocation with these command-line options:

```bash
# Set PHP memory limit (useful for constrained environments)
php bin/server.php --memory-limit=256M

# Configure specific table sizes (in number of entries)
php bin/server.php --memory-string-table-size=10000
php bin/server.php --memory-hash-table-size=5000
php bin/server.php --memory-list-table-size=1000
php bin/server.php --memory-set-table-size=1000
php bin/server.php --memory-zset-table-size=1000
php bin/server.php --memory-expiry-table-size=2000

# Combined example for a low-memory environment
php bin/server.php --memory-limit=128M --memory-string-table-size=1024 --memory-hash-table-size=1024
```

### Memory Usage Monitoring

You can monitor memory usage through the INFO command:

```
> INFO memory
# memory
swoole_used_memory:5623808
swoole_used_memory_peak:6172352
total_system_memory:8589934592
used_memory_lua:0
used_memory_scripts:0
```

### Recommendations for Different Environments

| Environment | RAM | Recommended Configuration |
|-------------|-----|---------------------------|
| Small VPS   | 512MB | `--memory-limit=256M --memory-string-table-size=1024` |
| Standard Server | 2-4GB | Default settings |
| High-Performance | 8GB+ | `--memory-string-table-size=1000000 --memory-hash-table-size=500000` |

For production environments with specific memory constraints, we recommend starting with conservative values and monitoring performance before gradually increasing allocation as needed.


## Architecture

SwooleRedis implements a modular architecture:

- **Server**: Main application entry point and event loop
- **Storage**: Implementations for different data types (strings, lists, hashes, sets, sorted sets, etc.)
- **Commands**: Command handlers for Redis protocol operations
- **Protocol**: Parsers and formatters for Redis protocol
- **Client**: Simplified client for connecting to the server

The project leverages Swoole Tables for shared memory storage, which provides:
- O(1) lookup time
- Process-safe operations
- Pre-allocated memory for better performance
- Built-in atomic operations

## Limitations

This is an educational implementation with several limitations compared to Redis:

- Limited command coverage
- Simplified implementations of some algorithms
- Basic transaction isolation
- Limited client-side tooling
- No clustering or replication
- Limited security features

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This project is licensed under the MIT License - see the LICENSE file for details.
