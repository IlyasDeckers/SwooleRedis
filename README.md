# SwooleRedis

SwooleRedis is a Redis-like server implementation using Swoole's high-performance asynchronous event-driven programming framework for PHP. This project demonstrates how to use Swoole Tables (shared memory) to build an in-memory data store similar to Redis.

## Features

- **Key-Value Operations**: Basic string storage with SET, GET, DEL commands
- **Expiration**: Support for EXPIRE and TTL commands
- **Data Structures**:
    - **Lists**: LPUSH, RPUSH, LPOP, RPOP operations
    - **Hashes**: HSET, HGET, HDEL operations
- **Pub/Sub Messaging**: SUBSCRIBE and PUBLISH commands
- **High Performance**: Uses Swoole's shared memory tables for low-latency operations
- **Concurrency**: Multi-process architecture supports high concurrency

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

$client->close();
```

See `examples/basic_usage.php` for more examples.

## Data Persistence

SwooleRedis now supports data persistence similar to Redis with two complementary methods:

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
- **Storage**: Implementations for different data types (strings, lists, hashes)
- **Commands**: Command handlers for Redis protocol operations
- **Protocol**: Parsers and formatters for Redis protocol
- **Client**: Simplified client for connecting to the server

The project leverages Swoole Tables for shared memory storage, which provides:
- O(1) lookup time
- Process-safe operations
- Pre-allocated memory for better performance
- Built-in atomic operations

## Limitations

This is a simplified implementation for educational purposes and has several limitations:

- Limited command set compared to Redis
- Basic protocol implementation (not full RESP)
- No persistence options
- No clustering or replication
- Limited security features

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This project is licensed under the MIT License - see the LICENSE file for details.
