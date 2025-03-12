<?php

namespace Ody\SwooleRedis\Command;

use Ody\SwooleRedis\Storage\StringStorage;
use Ody\SwooleRedis\Storage\KeyExpiry;
use Ody\SwooleRedis\Protocol\ResponseFormatter;

/**
 * Implements Redis string commands
 */
class StringCommands implements CommandInterface
{
    private StringStorage $storage;
    private KeyExpiry $expiry;
    private ResponseFormatter $formatter;

    public function __construct(
        StringStorage $storage,
        KeyExpiry $expiry,
        ResponseFormatter $formatter
    ) {
        $this->storage = $storage;
        $this->expiry = $expiry;
        $this->formatter = $formatter;
    }

    /**
     * {@inheritdoc}
     */
    public function execute(int $clientId, array $args): string
    {
        // Match the command from the command factory
        if (empty($args)) {
            return $this->formatter->error("Wrong number of arguments");
        }

        // Use the first argument to determine the command (passed from Server.php)
        $command = strtoupper(array_shift($args));

        switch ($command) {
            case 'PING':
                return $this->ping();

            case 'SET':
                return $this->set($args);

            case 'GET':
                return $this->get($args);

            default:
                return $this->formatter->error("Unknown command '{$command}'");
        }
    }

    /**
     * Implement PING command
     */
    private function ping(): string
    {
        return $this->formatter->simpleString("PONG");
    }

    /**
     * Implement SET command
     */
    private function set(array $args): string
    {
        if (count($args) < 2) {
            return $this->formatter->error("Wrong number of arguments for SET command");
        }

        $key = $args[0];
        $value = $args[1];

        // Check for optional EX/PX arguments
        if (isset($args[2]) && isset($args[3])) {
            if (strtoupper($args[2]) === 'EX') {
                $seconds = (int)$args[3];
                if ($seconds <= 0) {
                    return $this->formatter->error("Invalid expire time in SET");
                }
                $this->expiry->setExpiration($key, $seconds);
            }
        }

        $this->storage->set($key, $value);

        return $this->formatter->simpleString("OK");
    }

    /**
     * Implement GET command
     */
    private function get(array $args): string
    {
        if (count($args) !== 1) {
            return $this->formatter->error("Wrong number of arguments for GET command");
        }

        $key = $args[0];

        // Check for expiration
        if ($this->expiry->isExpired($key)) {
            $this->storage->delete($key);
            $this->expiry->removeExpiration($key);
            return $this->formatter->bulkString(null);
        }

        $value = $this->storage->get($key);

        return $this->formatter->bulkString($value);
    }
}