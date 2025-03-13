<?php

namespace Ody\SwooleRedis\Command;

use Ody\SwooleRedis\Storage\SetStorage;
use Ody\SwooleRedis\Protocol\ResponseFormatter;

/**
 * Implements Redis set commands
 */
class SetCommands implements CommandInterface
{
    private SetStorage $storage;
    private ResponseFormatter $formatter;

    public function __construct(
        SetStorage $storage,
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
            case 'SADD':
                return $this->sAdd($args);

            case 'SCARD':
                return $this->sCard($args);

            case 'SDIFF':
                return $this->sDiff($args);

            case 'SINTER':
                return $this->sInter($args);

            case 'SISMEMBER':
                return $this->sIsMember($args);

            case 'SMEMBERS':
                return $this->sMembers($args);

            case 'SMOVE':
                return $this->sMove($args);

            case 'SPOP':
                return $this->sPop($args);

            case 'SRANDMEMBER':
                return $this->sRandMember($args);

            case 'SREM':
                return $this->sRem($args);

            case 'SUNION':
                return $this->sUnion($args);

            default:
                return $this->formatter->error("Unknown command '{$command}'");
        }
    }

    /**
     * Implement SADD command
     */
    private function sAdd(array $args): string
    {
        if (count($args) < 2) {
            return $this->formatter->error("Wrong number of arguments for SADD command");
        }

        $key = $args[0];
        $members = array_slice($args, 1);

        $addedCount = 0;
        foreach ($members as $member) {
            $addedCount += $this->storage->sAdd($key, $member);
        }

        return $this->formatter->integer($addedCount);
    }

    /**
     * Implement SCARD command
     */
    private function sCard(array $args): string
    {
        if (count($args) !== 1) {
            return $this->formatter->error("Wrong number of arguments for SCARD command");
        }

        $key = $args[0];
        $count = $this->storage->sCard($key);

        return $this->formatter->integer($count);
    }

    /**
     * Implement SDIFF command
     */
    private function sDiff(array $args): string
    {
        if (count($args) < 1) {
            return $this->formatter->error("Wrong number of arguments for SDIFF command");
        }

        $keys = $args;
        $result = $this->storage->sDiff($keys);

        return $this->formatter->array($result);
    }

    /**
     * Implement SINTER command
     */
    private function sInter(array $args): string
    {
        if (count($args) < 1) {
            return $this->formatter->error("Wrong number of arguments for SINTER command");
        }

        $keys = $args;
        $result = $this->storage->sInter($keys);

        return $this->formatter->array($result);
    }

    /**
     * Implement SISMEMBER command
     */
    private function sIsMember(array $args): string
    {
        if (count($args) !== 2) {
            return $this->formatter->error("Wrong number of arguments for SISMEMBER command");
        }

        $key = $args[0];
        $member = $args[1];

        $result = $this->storage->sIsMember($key, $member) ? 1 : 0;

        return $this->formatter->integer($result);
    }

    /**
     * Implement SMEMBERS command
     */
    private function sMembers(array $args): string
    {
        if (count($args) !== 1) {
            return $this->formatter->error("Wrong number of arguments for SMEMBERS command");
        }

        $key = $args[0];
        $members = $this->storage->sMembers($key);

        return $this->formatter->array($members);
    }

    /**
     * Implement SMOVE command
     */
    private function sMove(array $args): string
    {
        if (count($args) !== 3) {
            return $this->formatter->error("Wrong number of arguments for SMOVE command");
        }

        $srcKey = $args[0];
        $dstKey = $args[1];
        $member = $args[2];

        $result = $this->storage->sMove($srcKey, $dstKey, $member);

        return $this->formatter->integer($result);
    }

    /**
     * Implement SPOP command
     */
    private function sPop(array $args): string
    {
        if (count($args) < 1 || count($args) > 2) {
            return $this->formatter->error("Wrong number of arguments for SPOP command");
        }

        $key = $args[0];
        $count = isset($args[1]) ? (int)$args[1] : 1;

        if ($count <= 0) {
            return $this->formatter->error("Count must be positive");
        }

        if ($count === 1) {
            $result = $this->storage->sPop($key);
            return $this->formatter->bulkString($result);
        }

        // For multiple pops, we'll just do it one by one for simplicity
        // A real Redis implementation would be more efficient
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $member = $this->storage->sPop($key);
            if ($member === null) {
                break;
            }
            $results[] = $member;
        }

        return $this->formatter->array($results);
    }

    /**
     * Implement SRANDMEMBER command
     */
    private function sRandMember(array $args): string
    {
        if (count($args) < 1 || count($args) > 2) {
            return $this->formatter->error("Wrong number of arguments for SRANDMEMBER command");
        }

        $key = $args[0];
        $count = isset($args[1]) ? (int)$args[1] : 1;

        $result = $this->storage->sRandMember($key, $count);

        if ($result === null) {
            return $this->formatter->nullResponse();
        }

        if (!is_array($result)) {
            return $this->formatter->bulkString($result);
        }

        return $this->formatter->array($result);
    }

    /**
     * Implement SREM command
     */
    private function sRem(array $args): string
    {
        if (count($args) < 2) {
            return $this->formatter->error("Wrong number of arguments for SREM command");
        }

        $key = $args[0];
        $members = array_slice($args, 1);

        $removedCount = 0;
        foreach ($members as $member) {
            $removedCount += $this->storage->sRem($key, $member);
        }

        return $this->formatter->integer($removedCount);
    }

    /**
     * Implement SUNION command
     */
    private function sUnion(array $args): string
    {
        if (count($args) < 1) {
            return $this->formatter->error("Wrong number of arguments for SUNION command");
        }

        $keys = $args;
        $result = $this->storage->sUnion($keys);

        return $this->formatter->array($result);
    }
}