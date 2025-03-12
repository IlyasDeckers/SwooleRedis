<?php

namespace Ody\SwooleRedis\Command;

use Ody\SwooleRedis\Storage\ListStorage;
use Ody\SwooleRedis\Protocol\ResponseFormatter;

/**
 * Implements Redis list commands
 */
class ListCommands implements CommandInterface
{
    private ListStorage $storage;
    private ResponseFormatter $formatter;

    public function __construct(
        ListStorage $storage,
        ResponseFormatter $formatter
    ) {
        $this->storage = $storage;
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
            case 'LPUSH':
                return $this->lpush($args);

            case 'RPUSH':
                return $this->rpush($args);

            case 'LPOP':
                return $this->lpop($args);

            case 'RPOP':
                return $this->rpop($args);

            case 'LLEN':
                return $this->llen($args);

            case 'LRANGE':
                return $this->lrange($args);

            default:
                return $this->formatter->error("Unknown command '{$command}'");
        }
    }

    /**
     * Implement LPUSH command
     */
    private function lpush(array $args): string
    {
        if (count($args) < 2) {
            return $this->formatter->error("Wrong number of arguments for LPUSH command");
        }

        $key = $args[0];
        $values = array_slice($args, 1);

        $count = 0;
        foreach ($values as $value) {
            $count = $this->storage->lpush($key, $value);
        }

        return $this->formatter->integer($count);
    }

    /**
     * Implement RPUSH command
     */
    private function rpush(array $args): string
    {
        if (count($args) < 2) {
            return $this->formatter->error("Wrong number of arguments for RPUSH command");
        }

        $key = $args[0];
        $values = array_slice($args, 1);

        $count = 0;
        foreach ($values as $value) {
            $count = $this->storage->rpush($key, $value);
        }

        return $this->formatter->integer($count);
    }

    /**
     * Implement LPOP command
     */
    private function lpop(array $args): string
    {
        if (count($args) !== 1) {
            return $this->formatter->error("Wrong number of arguments for LPOP command");
        }

        $key = $args[0];
        $value = $this->storage->lpop($key);

        return $this->formatter->bulkString($value);
    }

    /**
     * Implement RPOP command
     */
    private function rpop(array $args): string
    {
        if (count($args) !== 1) {
            return $this->formatter->error("Wrong number of arguments for RPOP command");
        }

        $key = $args[0];
        $value = $this->storage->rpop($key);

        return $this->formatter->bulkString($value);
    }

    /**
     * Implement LLEN command
     */
    private function llen(array $args): string
    {
        if (count($args) !== 1) {
            return $this->formatter->error("Wrong number of arguments for LLEN command");
        }

        $key = $args[0];
        $length = $this->storage->llen($key);

        return $this->formatter->integer($length);
    }

    /**
     * Implement LRANGE command
     */
    private function lrange(array $args): string
    {
        if (count($args) !== 3) {
            return $this->formatter->error("Wrong number of arguments for LRANGE command");
        }

        $key = $args[0];
        $start = (int)$args[1];
        $stop = (int)$args[2];

        $range = $this->storage->lrange($key, $start, $stop);

        return $this->formatter->array($range);
    }
}