<?php

namespace Ody\SwooleRedis\Command;

use Ody\SwooleRedis\Storage\StringStorage;
use Ody\SwooleRedis\Storage\KeyExpiry;
use Ody\SwooleRedis\Protocol\ResponseFormatter;

/**
 * Implements Redis key commands
 */
class KeyCommands implements CommandInterface
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
        if (empty($args)) {
            return $this->formatter->error("Wrong number of arguments");
        }

        // Use the first argument to determine the command
        $command = strtoupper(array_shift($args));

        switch ($command) {
            case 'DEL':
                return $this->del($args);

            case 'EXPIRE':
                return $this->expire($args);

            case 'TTL':
                return $this->ttl($args);

            default:
                return $this->formatter->error("Unknown command '{$command}'");
        }
    }

    /**
     * Implement DEL command
     */
    private function del(array $args): string
    {
        if (empty($args)) {
            return $this->formatter->error("Wrong number of arguments for DEL command");
        }

        $deleted = 0;

        foreach ($args as $key) {
            if ($this->storage->delete($key)) {
                $this->expiry->removeExpiration($key);
                $deleted++;
            }
        }

        return $this->formatter->integer($deleted);
    }

    /**
     * Implement EXPIRE command
     */
    private function expire(array $args): string
    {
        if (count($args) !== 2) {
            return $this->formatter->error("Wrong number of arguments for EXPIRE command");
        }

        $key = $args[0];
        $seconds = (int)$args[1];

        if (!$this->storage->exists($key)) {
            return $this->formatter->integer(0);
        }

        if ($seconds <= 0) {
            // Negative or zero TTL means delete the key immediately
            $this->storage->delete($key);
            $this->expiry->removeExpiration($key);
            return $this->formatter->integer(1);
        }

        $this->expiry->setExpiration($key, $seconds);

        return $this->formatter->integer(1);
    }

    /**
     * Implement TTL command
     */
    private function ttl(array $args): string
    {
        if (count($args) !== 1) {
            return $this->formatter->error("Wrong number of arguments for TTL command");
        }

        $key = $args[0];

        if (!$this->storage->exists($key)) {
            return $this->formatter->integer(-2);
        }

        $ttl = $this->expiry->getTtl($key);

        return $this->formatter->integer($ttl);
    }
}