<?php

namespace Ody\SwooleRedis\Command;

use Ody\SwooleRedis\Storage\HashStorage;
use Ody\SwooleRedis\Protocol\ResponseFormatter;

/**
 * Implements Redis hash commands
 */
class HashCommands implements CommandInterface
{
    private HashStorage $storage;
    private ResponseFormatter $formatter;

    public function __construct(
        HashStorage $storage,
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
            case 'HSET':
                return $this->hSet($args);

            case 'HGET':
                return $this->hGet($args);

            case 'HDEL':
                return $this->hDel($args);

            case 'HKEYS':
                return $this->hKeys($args);

            case 'HVALS':
                return $this->hVals($args);

            case 'HGETALL':
                return $this->hGetAll($args);

            default:
                return $this->formatter->error("Unknown command '{$command}'");
        }
    }

    /**
     * Implement HSET command
     */
    private function hSet(array $args): string
    {
        if (count($args) < 3) {
            return $this->formatter->error("Wrong number of arguments for HSET command");
        }

        $key = $args[0];
        $field = $args[1];
        $value = $args[2];

        $isNew = $this->storage->hSet($key, $field, $value);

        return $this->formatter->integer($isNew ? 1 : 0);
    }

    /**
     * Implement HGET command
     */
    private function hGet(array $args): string
    {
        if (count($args) !== 2) {
            return $this->formatter->error("Wrong number of arguments for HGET command");
        }

        $key = $args[0];
        $field = $args[1];

        $value = $this->storage->hGet($key, $field);

        return $this->formatter->bulkString($value);
    }

    /**
     * Implement HDEL command
     */
    private function hDel(array $args): string
    {
        if (count($args) < 2) {
            return $this->formatter->error("Wrong number of arguments for HDEL command");
        }

        $key = $args[0];
        $fields = array_slice($args, 1);

        $deleted = 0;
        foreach ($fields as $field) {
            if ($this->storage->hDel($key, $field)) {
                $deleted++;
            }
        }

        return $this->formatter->integer($deleted);
    }

    /**
     * Implement HKEYS command
     */
    private function hKeys(array $args): string
    {
        if (count($args) !== 1) {
            return $this->formatter->error("Wrong number of arguments for HKEYS command");
        }

        $key = $args[0];
        $keys = $this->storage->hKeys($key);

        return $this->formatter->array($keys);
    }

    /**
     * Implement HVALS command
     */
    private function hVals(array $args): string
    {
        if (count($args) !== 1) {
            return $this->formatter->error("Wrong number of arguments for HVALS command");
        }

        $key = $args[0];
        $values = $this->storage->hVals($key);

        return $this->formatter->array($values);
    }

    /**
     * Implement HGETALL command
     */
    private function hGetAll(array $args): string
    {
        if (count($args) !== 1) {
            return $this->formatter->error("Wrong number of arguments for HGETALL command");
        }

        $key = $args[0];
        $data = $this->storage->hGetAll($key);

        // Flatten key-value pairs for Redis protocol
        $result = [];
        foreach ($data as $field => $value) {
            $result[] = $field;
            $result[] = $value;
        }

        return $this->formatter->array($result);
    }
}