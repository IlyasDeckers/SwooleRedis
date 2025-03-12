<?php

namespace Ody\SwooleRedis\Client;

/**
 * A simple client for connecting to the SwooleRedis server
 */
class Client
{
    private $socket;
    private string $host;
    private int $port;

    /**
     * Create a new client instance
     *
     * @param string $host The server host
     * @param int $port The server port
     */
    public function __construct(string $host = '127.0.0.1', int $port = 6380)
    {
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * Connect to the server
     *
     * @return bool True if connection successful
     */
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

        return true;
    }

    /**
     * Send a command to the server
     *
     * @param string $command The command to send
     * @return string The server response
     */
    public function command(string $command): string
    {
        if (!$this->socket) {
            return "-ERR Not connected\r\n";
        }

        $result = socket_write($this->socket, $command, strlen($command));
        if ($result === false) {
            return "-ERR Write failed: " . socket_strerror(socket_last_error($this->socket)) . "\r\n";
        }

        // Read response
        $response = '';
        $buffer = '';

        do {
            $buffer = socket_read($this->socket, 2048);
            if ($buffer === false) {
                return "-ERR Read failed: " . socket_strerror(socket_last_error($this->socket)) . "\r\n";
            }
            $response .= $buffer;

            // Add a small delay to allow buffers to fill
            usleep(1000);
        } while (socket_recv($this->socket, $peek, 1, MSG_PEEK | MSG_DONTWAIT) > 0);

        return $response;
    }

    /**
     * Close the connection
     */
    public function close(): void
    {
        if ($this->socket) {
            socket_close($this->socket);
        }
    }

    /**
     * Set a key-value pair
     *
     * @param string $key The key
     * @param string $value The value
     * @param int|null $expiry Optional expiry time in seconds
     * @return string The server response
     */
    public function set(string $key, string $value, ?int $expiry = null): string
    {
        $command = "SET {$key} {$value}";

        if ($expiry !== null) {
            $command .= " EX {$expiry}";
        }

        return $this->command($command);
    }

    /**
     * Get a value by key
     *
     * @param string $key The key
     * @return string The server response
     */
    public function get(string $key): string
    {
        return $this->command("GET {$key}");
    }

    /**
     * Delete one or more keys
     *
     * @param string ...$keys The keys to delete
     * @return string The server response
     */
    public function del(string ...$keys): string
    {
        return $this->command("DEL " . implode(' ', $keys));
    }

    /**
     * Set an expiry time for a key
     *
     * @param string $key The key
     * @param int $seconds The expiry time in seconds
     * @return string The server response
     */
    public function expire(string $key, int $seconds): string
    {
        return $this->command("EXPIRE {$key} {$seconds}");
    }

    /**
     * Get the TTL of a key
     *
     * @param string $key The key
     * @return string The server response
     */
    public function ttl(string $key): string
    {
        return $this->command("TTL {$key}");
    }

    /**
     * Push a value to the front of a list
     *
     * @param string $key The list key
     * @param string ...$values The values to push
     * @return string The server response
     */
    public function lPush(string $key, string ...$values): string
    {
        return $this->command("LPUSH {$key} " . implode(' ', $values));
    }

    /**
     * Push a value to the back of a list
     *
     * @param string $key The list key
     * @param string ...$values The values to push
     * @return string The server response
     */
    public function rPush(string $key, string ...$values): string
    {
        return $this->command("RPUSH {$key} " . implode(' ', $values));
    }

    /**
     * Pop a value from the front of a list
     *
     * @param string $key The list key
     * @return string The server response
     */
    public function lPop(string $key): string
    {
        return $this->command("LPOP {$key}");
    }

    /**
     * Pop a value from the back of a list
     *
     * @param string $key The list key
     * @return string The server response
     */
    public function rPop(string $key): string
    {
        return $this->command("RPOP {$key}");
    }

    /**
     * Set a field in a hash
     *
     * @param string $key The hash key
     * @param string $field The field name
     * @param string $value The field value
     * @return string The server response
     */
    public function hSet(string $key, string $field, string $value): string
    {
        return $this->command("HSET {$key} {$field} {$value}");
    }

    /**
     * Get a field from a hash
     *
     * @param string $key The hash key
     * @param string $field The field name
     * @return string The server response
     */
    public function hGet(string $key, string $field): string
    {
        return $this->command("HGET {$key} {$field}");
    }

    /**
     * Delete a field from a hash
     *
     * @param string $key The hash key
     * @param string $field The field name
     * @return string The server response
     */
    public function hDel(string $key, string $field): string
    {
        return $this->command("HDEL {$key} {$field}");
    }
}