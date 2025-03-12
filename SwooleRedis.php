<?php

/**
 * Simple Redis-like implementation using Swoole
 *
 * This example implements a subset of Redis commands including:
 * - Basic key-value operations (GET, SET, DEL)
 * - Hash operations (HGET, HSET, HDEL)
 * - List operations (LPUSH, LPOP, RPUSH, RPOP)
 * - Expiration (EXPIRE, TTL)
 * - Pub/Sub functionality (PUBLISH, SUBSCRIBE)
 */

declare(strict_types=1);

use Swoole\Server;
use Swoole\Timer;

class SwooleRedis
{
    private Server $server;
    private \Swoole\Table $stringTable;
    private \Swoole\Table $expiryTable;
    private array $subscribers = [];
    private \Swoole\Table $listPointers;
    private array $listData = []; // Still need arrays for lists
    private \Swoole\Table $hashTable;
    private int $port;
    private string $host;

    public function __construct(string $host = '127.0.0.1', int $port = 6380)
    {
        $this->host = $host;
        $this->port = $port;

        // Initialize Swoole tables
        $this->initializeTables();

        $this->server = new Server($host, $port, SWOOLE_BASE);

        $this->server->set([
            'worker_num' => swoole_cpu_num(),
            'max_conn' => 10000,
            'backlog' => 128,
        ]);

        $this->setupEventHandlers();
        $this->startExpirationChecker();
    }

    private function initializeTables(): void
    {
        // String table for key-value storage
        $this->stringTable = new \Swoole\Table(1024 * 1024); // 1M rows
        $this->stringTable->column('value', \Swoole\Table::TYPE_STRING, 1024); // Max value size 1KB
        $this->stringTable->create();

        // Expiry table for tracking key expiration
        $this->expiryTable = new \Swoole\Table(1024 * 1024); // 1M rows
        $this->expiryTable->column('expire_at', \Swoole\Table::TYPE_INT); // Expiration timestamp
        $this->expiryTable->create();

        // List pointer table (tracks head/tail of lists)
        $this->listPointers = new \Swoole\Table(1024 * 1024); // 1M rows
        $this->listPointers->column('head', \Swoole\Table::TYPE_INT);
        $this->listPointers->column('tail', \Swoole\Table::TYPE_INT);
        $this->listPointers->column('size', \Swoole\Table::TYPE_INT);
        $this->listPointers->create();

        // Hash table for field-value pairs
        $this->hashTable = new \Swoole\Table(1024 * 1024); // 1M rows
        $this->hashTable->column('field', \Swoole\Table::TYPE_STRING, 128); // Field name (max 128 bytes)
        $this->hashTable->column('value', \Swoole\Table::TYPE_STRING, 1024); // Value (max 1KB)
        $this->hashTable->column('key', \Swoole\Table::TYPE_STRING, 128);    // Parent key name
        $this->hashTable->create();
    }

    private function setupEventHandlers(): void
    {
        $this->server->on('connect', function ($server, $fd) {
            echo "Client connected: {$fd}\n";
        });

        $this->server->on('receive', function ($server, $fd, $reactorId, $data) {
            $command = $this->parseCommand($data);

            if (!$command) {
                $server->send($fd, "-ERR Invalid command format\r\n");
                return;
            }

            $response = $this->processCommand($fd, $command);
            $server->send($fd, $response);
        });

        $this->server->on('close', function ($server, $fd) {
            // Unsubscribe if the client was subscribed to any channels
            foreach ($this->subscribers as $channel => $subscribers) {
                if (($key = array_search($fd, $subscribers)) !== false) {
                    unset($this->subscribers[$channel][$key]);
                    // Remove the channel if no subscribers left
                    if (empty($this->subscribers[$channel])) {
                        unset($this->subscribers[$channel]);
                    }
                }
            }
            echo "Client disconnected: {$fd}\n";
        });
    }

    private function parseCommand(string $rawData): ?array
    {
        // Simple command parser (actual Redis uses RESP protocol)
        $data = trim($rawData);
        $parts = explode(' ', $data);

        if (empty($parts)) {
            return null;
        }

        $command = strtoupper($parts[0]);
        $arguments = array_slice($parts, 1);

        return ['command' => $command, 'args' => $arguments];
    }

    private function processCommand(int $fd, array $command): string
    {
        $cmd = $command['command'];
        $args = $command['args'];

        switch ($cmd) {
            // String operations
            case 'PING':
                return "+PONG\r\n";

            case 'SET':
                if (count($args) < 2) {
                    return "-ERR Wrong number of arguments for SET command\r\n";
                }
                $key = $args[0];
                $value = $args[1];

                // Check for optional EX/PX arguments
                if (isset($args[2]) && isset($args[3])) {
                    if (strtoupper($args[2]) === 'EX') {
                        $this->setExpiration($key, (int)$args[3]);
                    }
                }

                $this->stringTable->set($key, ['value' => $value]);
                return "+OK\r\n";

            case 'GET':
                if (count($args) !== 1) {
                    return "-ERR Wrong number of arguments for GET command\r\n";
                }
                $key = $args[0];

                if (!$this->stringTable->exist($key) || $this->isExpired($key)) {
                    if ($this->isExpired($key)) {
                        $this->delete($key);
                    }
                    return "$-1\r\n"; // Redis returns null for non-existent keys
                }

                $row = $this->stringTable->get($key);
                $value = $row['value'];
                return "$" . strlen($value) . "\r\n" . $value . "\r\n";

            case 'DEL':
                if (count($args) < 1) {
                    return "-ERR Wrong number of arguments for DEL command\r\n";
                }

                $deleted = 0;
                foreach ($args as $key) {
                    if ($this->stringTable->exist($key)) {
                        $this->stringTable->del($key);
                        $this->expiryTable->del($key);
                        $deleted++;
                    }
                }

                return ":{$deleted}\r\n";

            // Expiration
            case 'EXPIRE':
                if (count($args) !== 2) {
                    return "-ERR Wrong number of arguments for EXPIRE command\r\n";
                }

                $key = $args[0];
                $seconds = (int)$args[1];

                if (!$this->stringTable->exist($key)) {
                    return ":0\r\n"; // Key doesn't exist
                }

                $this->setExpiration($key, $seconds);
                return ":1\r\n";

            case 'TTL':
                if (count($args) !== 1) {
                    return "-ERR Wrong number of arguments for TTL command\r\n";
                }

                $key = $args[0];

                if (!$this->stringTable->exist($key)) {
                    return ":-2\r\n"; // Key doesn't exist
                }

                if (!$this->expiryTable->exist($key)) {
                    return ":-1\r\n"; // Key exists but has no expiration
                }

                $expiry = $this->expiryTable->get($key);
                $ttl = $expiry['expire_at'] - time();
                return ":{$ttl}\r\n";

            // List operations
            case 'LPUSH':
                if (count($args) < 2) {
                    return "-ERR Wrong number of arguments for LPUSH command\r\n";
                }

                $key = $args[0];
                $values = array_slice($args, 1);

                if (!isset($this->listData[$key])) {
                    $this->listData[$key] = [];
                }

                $newLength = 0;
                foreach ($values as $value) {
                    array_unshift($this->listData[$key], $value);
                    $newLength++;
                }

                return ":" . count($this->listData[$key]) . "\r\n";

            case 'LPOP':
                if (count($args) !== 1) {
                    return "-ERR Wrong number of arguments for LPOP command\r\n";
                }

                $key = $args[0];

                if (!isset($this->listData[$key]) || empty($this->listData[$key])) {
                    return "$-1\r\n";
                }

                $value = array_shift($this->listData[$key]);

                if (empty($this->listData[$key])) {
                    unset($this->listData[$key]);
                }

                return "$" . strlen($value) . "\r\n" . $value . "\r\n";

            case 'RPUSH':
                if (count($args) < 2) {
                    return "-ERR Wrong number of arguments for RPUSH command\r\n";
                }

                $key = $args[0];
                $values = array_slice($args, 1);

                if (!isset($this->listData[$key])) {
                    $this->listData[$key] = [];
                }

                $newLength = 0;
                foreach ($values as $value) {
                    $this->listData[$key][] = $value;
                    $newLength++;
                }

                return ":" . count($this->listData[$key]) . "\r\n";

            case 'RPOP':
                if (count($args) !== 1) {
                    return "-ERR Wrong number of arguments for RPOP command\r\n";
                }

                $key = $args[0];

                if (!isset($this->listData[$key]) || empty($this->listData[$key])) {
                    return "$-1\r\n";
                }

                $value = array_pop($this->listData[$key]);

                if (empty($this->listData[$key])) {
                    unset($this->listData[$key]);
                }

                return "$" . strlen($value) . "\r\n" . $value . "\r\n";

            // Hash operations
            case 'HSET':
                if (count($args) < 3) {
                    return "-ERR Wrong number of arguments for HSET command\r\n";
                }

                $key = $args[0];
                $field = $args[1];
                $value = $args[2];

                // Create a unique identifier for this hash field
                $hashId = $key . ':' . $field;

                $isNew = !$this->hashTable->exist($hashId);
                $this->hashTable->set($hashId, [
                    'key' => $key,
                    'field' => $field,
                    'value' => $value
                ]);

                return ":" . ($isNew ? 1 : 0) . "\r\n";

            case 'HGET':
                if (count($args) !== 2) {
                    return "-ERR Wrong number of arguments for HGET command\r\n";
                }

                $key = $args[0];
                $field = $args[1];

                // Create a unique identifier for this hash field
                $hashId = $key . ':' . $field;

                if (!$this->hashTable->exist($hashId)) {
                    return "$-1\r\n";
                }

                $row = $this->hashTable->get($hashId);
                $value = $row['value'];
                return "$" . strlen($value) . "\r\n" . $value . "\r\n";

            case 'HDEL':
                if (count($args) < 2) {
                    return "-ERR Wrong number of arguments for HDEL command\r\n";
                }

                $key = $args[0];
                $fields = array_slice($args, 1);

                $deleted = 0;
                foreach ($fields as $field) {
                    $hashId = $key . ':' . $field;
                    if ($this->hashTable->exist($hashId)) {
                        $this->hashTable->del($hashId);
                        $deleted++;
                    }
                }

                return ":{$deleted}\r\n";

            // Pub/Sub operations
            case 'SUBSCRIBE':
                if (count($args) < 1) {
                    return "-ERR Wrong number of arguments for SUBSCRIBE command\r\n";
                }

                $channels = $args;
                $responses = [];

                foreach ($channels as $channel) {
                    if (!isset($this->subscribers[$channel])) {
                        $this->subscribers[$channel] = [];
                    }

                    if (!in_array($fd, $this->subscribers[$channel])) {
                        $this->subscribers[$channel][] = $fd;
                    }

                    $response = "*3\r\n";
                    $response .= "$10\r\n";
                    $response .= "subscribe\r\n";
                    $response .= "$" . strlen($channel) . "\r\n";
                    $response .= "{$channel}\r\n";
                    $response .= ":" . count($this->subscribers[$channel]) . "\r\n";

                    $responses[] = $response;
                }

                return implode('', $responses);

            case 'PUBLISH':
                if (count($args) !== 2) {
                    return "-ERR Wrong number of arguments for PUBLISH command\r\n";
                }

                $channel = $args[0];
                $message = $args[1];

                if (!isset($this->subscribers[$channel])) {
                    return ":0\r\n"; // No subscribers
                }

                $count = 0;
                foreach ($this->subscribers[$channel] as $subscriber) {
                    $response = "*3\r\n";
                    $response .= "$7\r\n";
                    $response .= "message\r\n";
                    $response .= "$" . strlen($channel) . "\r\n";
                    $response .= "{$channel}\r\n";
                    $response .= "$" . strlen($message) . "\r\n";
                    $response .= "{$message}\r\n";

                    $this->server->send($subscriber, $response);
                    $count++;
                }

                return ":{$count}\r\n";

            default:
                return "-ERR Unknown command '{$cmd}'\r\n";
        }
    }

    private function setExpiration(string $key, int $seconds): void
    {
        $this->expiryTable->set($key, ['expire_at' => time() + $seconds]);
    }

    private function isExpired(string $key): bool
    {
        if (!$this->expiryTable->exist($key)) {
            return false;
        }

        $expiry = $this->expiryTable->get($key);
        return time() > $expiry['expire_at'];
    }

    private function delete(string $key): void
    {
        $this->stringTable->del($key);
        $this->expiryTable->del($key);
    }

    private function startExpirationChecker(): void
    {
        // Check for expired keys every second
        Timer::tick(1000, function () {
            foreach ($this->expiryTable as $key => $row) {
                if (time() > $row['expire_at']) {
                    $this->delete($key);
                }
            }
        });
    }

    public function start(): void
    {
        echo "SwooleRedis server starting on {$this->host}:{$this->port}\n";
        $this->server->start();
    }
}

// Create and start the server
$redisServer = new SwooleRedis();
$redisServer->start();