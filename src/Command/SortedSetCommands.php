<?php

namespace Ody\SwooleRedis\Command;

use Ody\SwooleRedis\Storage\SortedSetStorage;
use Ody\SwooleRedis\Protocol\ResponseFormatter;

/**
 * Implements Redis sorted set commands
 */
class SortedSetCommands implements CommandInterface
{
    private SortedSetStorage $storage;
    private ResponseFormatter $formatter;

    public function __construct(
        SortedSetStorage $storage,
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
            case 'ZADD':
                return $this->zAdd($args);

            case 'ZCARD':
                return $this->zCard($args);

            case 'ZCOUNT':
                return $this->zCount($args);

            case 'ZINCRBY':
                return $this->zIncrBy($args);

            case 'ZRANGE':
                return $this->zRange($args);

            case 'ZRANGEBYSCORE':
                return $this->zRangeByScore($args);

            case 'ZREM':
                return $this->zRem($args);

            case 'ZREVRANGE':
                return $this->zRevRange($args);

            case 'ZSCORE':
                return $this->zScore($args);

            default:
                return $this->formatter->error("Unknown command '{$command}'");
        }
    }

    /**
     * Implement ZADD command
     */
    private function zAdd(array $args): string
    {
        if (count($args) < 3 || count($args) % 2 !== 1) {
            return $this->formatter->error("Wrong number of arguments for ZADD command");
        }

        $key = $args[0];
        $addedCount = 0;

        // Process score-member pairs
        for ($i = 1; $i < count($args); $i += 2) {
            if (!is_numeric($args[$i])) {
                return $this->formatter->error("Score must be a valid floating point number");
            }

            $score = (float)$args[$i];
            $member = $args[$i + 1];

            $addedCount += $this->storage->zAdd($key, $score, $member);
        }

        return $this->formatter->integer($addedCount);
    }

    /**
     * Implement ZCARD command
     */
    private function zCard(array $args): string
    {
        if (count($args) !== 1) {
            return $this->formatter->error("Wrong number of arguments for ZCARD command");
        }

        $key = $args[0];
        $count = $this->storage->zCard($key);

        return $this->formatter->integer($count);
    }

    /**
     * Implement ZCOUNT command
     */
    private function zCount(array $args): string
    {
        if (count($args) !== 3) {
            return $this->formatter->error("Wrong number of arguments for ZCOUNT command");
        }

        $key = $args[0];
        $min = (float)$args[1];
        $max = (float)$args[2];

        $count = $this->storage->zCount($key, $min, $max);

        return $this->formatter->integer($count);
    }

    /**
     * Implement ZINCRBY command
     */
    private function zIncrBy(array $args): string
    {
        if (count($args) !== 3) {
            return $this->formatter->error("Wrong number of arguments for ZINCRBY command");
        }

        $key = $args[0];
        $increment = (float)$args[1];
        $member = $args[2];

        $newScore = $this->storage->zIncrBy($key, $increment, $member);

        return $this->formatter->bulkString((string)$newScore);
    }

    /**
     * Implement ZRANGE command
     */
    private function zRange(array $args): string
    {
        if (count($args) < 3) {
            return $this->formatter->error("Wrong number of arguments for ZRANGE command");
        }

        $key = $args[0];
        $start = (int)$args[1];
        $stop = (int)$args[2];

        $withScores = false;
        if (isset($args[3]) && strtoupper($args[3]) === 'WITHSCORES') {
            $withScores = true;
        }

        $result = $this->storage->zRange($key, $start, $stop, $withScores);

        return $this->formatter->array($result);
    }

    /**
     * Implement ZRANGEBYSCORE command
     */
    private function zRangeByScore(array $args): string
    {
        if (count($args) < 3) {
            return $this->formatter->error("Wrong number of arguments for ZRANGEBYSCORE command");
        }

        $key = $args[0];
        $min = (float)$args[1];
        $max = (float)$args[2];

        $withScores = false;
        if (isset($args[3]) && strtoupper($args[3]) === 'WITHSCORES') {
            $withScores = true;
        }

        $result = $this->storage->zRangeByScore($key, $min, $max, $withScores);

        return $this->formatter->array($result);
    }

    /**
     * Implement ZREM command
     */
    private function zRem(array $args): string
    {
        if (count($args) < 2) {
            return $this->formatter->error("Wrong number of arguments for ZREM command");
        }

        $key = $args[0];
        $members = array_slice($args, 1);

        $removedCount = 0;
        foreach ($members as $member) {
            $removedCount += $this->storage->zRem($key, $member);
        }

        return $this->formatter->integer($removedCount);
    }

    /**
     * Implement ZREVRANGE command
     */
    private function zRevRange(array $args): string
    {
        if (count($args) < 3) {
            return $this->formatter->error("Wrong number of arguments for ZREVRANGE command");
        }

        $key = $args[0];
        $start = (int)$args[1];
        $stop = (int)$args[2];

        $withScores = false;
        if (isset($args[3]) && strtoupper($args[3]) === 'WITHSCORES') {
            $withScores = true;
        }

        $result = $this->storage->zRange($key, $start, $stop, $withScores, true);

        return $this->formatter->array($result);
    }

    /**
     * Implement ZSCORE command
     */
    private function zScore(array $args): string
    {
        if (count($args) !== 2) {
            return $this->formatter->error("Wrong number of arguments for ZSCORE command");
        }

        $key = $args[0];
        $member = $args[1];

        $score = $this->storage->zScore($key, $member);

        if ($score === null) {
            return $this->formatter->nullResponse();
        }

        return $this->formatter->bulkString((string)$score);
    }
}