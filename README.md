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