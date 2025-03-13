<?php

namespace Ody\SwooleRedis\Command;

use Ody\SwooleRedis\Storage\BitMapStorage;
use Ody\SwooleRedis\Protocol\ResponseFormatter;

/**
 * Implements Redis bitmap commands
 */
class BitMapCommands implements CommandInterface
{
    private BitMapStorage $storage;
    private ResponseFormatter $formatter;

    public function __construct(
        BitMapStorage $storage,
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
            case 'GETBIT':
                return $this->getBit($args);

            case 'SETBIT':
                return $this->setBit($args);

            case 'BITCOUNT':
                return $this->bitCount($args);

            case 'BITOP':
                return $this->bitOp($args);

            case 'BITPOS':
                return $this->bitPos($args);

            default:
                return $this->formatter->error("Unknown command '{$command}'");
        }
    }

    /**
     * Implement GETBIT command
     */
    private function getBit(array $args): string
    {
        if (count($args) !== 2) {
            return $this->formatter->error("Wrong number of arguments for GETBIT command");
        }

        $key = $args[0];

        // Validate offset
        if (!is_numeric($args[1])) {
            return $this->formatter->error("bit offset is not an integer or out of range");
        }

        $offset = (int)$args[1];

        if ($offset < 0) {
            return $this->formatter->error("bit offset is not an integer or out of range");
        }

        try {
            $bit = $this->storage->getBit($key, $offset);
            return $this->formatter->integer($bit);
        } catch (\Exception $e) {
            return $this->formatter->error($e->getMessage());
        }
    }

    /**
     * Implement SETBIT command
     */
    private function setBit(array $args): string
    {
        if (count($args) !== 3) {
            return $this->formatter->error("Wrong number of arguments for SETBIT command");
        }

        $key = $args[0];

        // Validate offset
        if (!is_numeric($args[1])) {
            return $this->formatter->error("bit offset is not an integer or out of range");
        }

        $offset = (int)$args[1];

        if ($offset < 0) {
            return $this->formatter->error("bit offset is not an integer or out of range");
        }

        // Validate value
        if (!is_numeric($args[2]) || ($args[2] !== '0' && $args[2] !== '1')) {
            return $this->formatter->error("bit is not an integer or out of range");
        }

        $value = (int)$args[2];

        try {
            $bit = $this->storage->setBit($key, $offset, $value);
            return $this->formatter->integer($bit);
        } catch (\Exception $e) {
            return $this->formatter->error($e->getMessage());
        }
    }

    /**
     * Implement BITCOUNT command
     */
    private function bitCount(array $args): string
    {
        if (count($args) < 1 || count($args) > 3) {
            return $this->formatter->error("Wrong number of arguments for BITCOUNT command");
        }

        $key = $args[0];
        $start = 0;
        $end = -1;

        // Parse optional start/end parameters
        if (count($args) >= 2) {
            if (!is_numeric($args[1])) {
                return $this->formatter->error("value is not an integer or out of range");
            }
            $start = (int)$args[1];
        }

        if (count($args) >= 3) {
            if (!is_numeric($args[2])) {
                return $this->formatter->error("value is not an integer or out of range");
            }
            $end = (int)$args[2];
        }

        try {
            $count = $this->storage->bitCount($key, $start, $end);
            return $this->formatter->integer($count);
        } catch (\Exception $e) {
            return $this->formatter->error($e->getMessage());
        }
    }

    /**
     * Implement BITOP command
     */
    private function bitOp(array $args): string
    {
        if (count($args) < 2) {
            return $this->formatter->error("Wrong number of arguments for BITOP command");
        }

        $operation = strtoupper($args[0]);
        $destKey = $args[1];
        $sourceKeys = array_slice($args, 2);

        // Validate operation
        $validOps = ['AND', 'OR', 'XOR', 'NOT'];
        if (!in_array($operation, $validOps)) {
            return $this->formatter->error("BITOP with unsupported operation");
        }

        if (empty($sourceKeys)) {
            return $this->formatter->error("BITOP requires at least one source key");
        }

        if ($operation === 'NOT' && count($sourceKeys) !== 1) {
            return $this->formatter->error("BITOP NOT must be called with a single source key");
        }

        try {
            $length = $this->storage->bitOp($operation, $destKey, $sourceKeys);
            return $this->formatter->integer($length);
        } catch (\Exception $e) {
            return $this->formatter->error($e->getMessage());
        }
    }

    /**
     * Implement BITPOS command
     */
    private function bitPos(array $args): string
    {
        if (count($args) < 2 || count($args) > 4) {
            return $this->formatter->error("Wrong number of arguments for BITPOS command");
        }

        $key = $args[0];

        // Validate bit
        if (!is_numeric($args[1]) || ($args[1] !== '0' && $args[1] !== '1')) {
            return $this->formatter->error("bit is not an integer or out of range");
        }

        $bit = (int)$args[1];
        $start = 0;
        $end = -1;

        // Parse optional start/end parameters
        if (count($args) >= 3) {
            if (!is_numeric($args[2])) {
                return $this->formatter->error("value is not an integer or out of range");
            }
            $start = (int)$args[2];
        }

        if (count($args) >= 4) {
            if (!is_numeric($args[3])) {
                return $this->formatter->error("value is not an integer or out of range");
            }
            $end = (int)$args[3];
        }

        try {
            $pos = $this->storage->bitPos($key, $bit, $start, $end);
            return $this->formatter->integer($pos);
        } catch (\Exception $e) {
            return $this->formatter->error($e->getMessage());
        }
    }
}