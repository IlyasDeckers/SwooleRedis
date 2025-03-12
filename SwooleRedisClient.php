<?php

/**
 * Simple client to test the Swoole Redis Server
 * This demonstrates how to connect and interact with the server
 */

class RedisClient
{
    private $socket;
    private string $host;
    private int $port;

    public function __construct(string $host = '127.0.0.1', int $port = 6380)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public function connect(): bool
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$this->socket) {
            echo "Failed to create socket: " . socket_strerror(socket_last_error()) . "\n";
            return false;
        }

        $result = socket_connect($this->socket, $this->host, $this->port);
        if (!$result) {
            echo "Failed to connect: " . socket_strerror(socket_last_error($this->socket)) . "\n";
            return false;
        }

        echo "Connected to Swoole Redis server at {$this->host}:{$this->port}\n";
        return true;
    }

    public function command(string $command): string
    {
        socket_write($this->socket, $command, strlen($command));

        // Read response
        $response = '';
        $buffer = '';

        do {
            $buffer = socket_read($this->socket, 2048);
            if ($buffer === false) {
                echo "Read failed: " . socket_strerror(socket_last_error($this->socket)) . "\n";
                break;
            }
            $response .= $buffer;
        } while (strlen($buffer) === 2048); // Continue if we might have more data

        return $response;
    }

    public function close(): void
    {
        if ($this->socket) {
            socket_close($this->socket);
            echo "Connection closed\n";
        }
    }
}

// Example usage
$client = new RedisClient();
if ($client->connect()) {
    // Test basic string operations
    echo "PING: " . $client->command("PING") . "\n";
    echo "SET test 123: " . $client->command("SET test 123") . "\n";
    echo "GET test: " . $client->command("GET test") . "\n";

    // Test expiration
    echo "EXPIRE test 10: " . $client->command("EXPIRE test 10") . "\n";
    echo "TTL test: " . $client->command("TTL test") . "\n";

    // Test list operations
    echo "LPUSH mylist first: " . $client->command("LPUSH mylist first") . "\n";
    echo "LPUSH mylist second: " . $client->command("LPUSH mylist second") . "\n";
    echo "RPUSH mylist third: " . $client->command("RPUSH mylist third") . "\n";
    echo "LPOP mylist: " . $client->command("LPOP mylist") . "\n";
    echo "RPOP mylist: " . $client->command("RPOP mylist") . "\n";

    // Test hash operations
    echo "HSET user name John: " . $client->command("HSET user name John") . "\n";
    echo "HGET user name: " . $client->command("HGET user name") . "\n";
    echo "HDEL user name: " . $client->command("HDEL user name") . "\n";

    $client->close();
}