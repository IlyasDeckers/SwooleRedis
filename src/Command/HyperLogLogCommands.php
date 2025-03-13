<?php

namespace Ody\SwooleRedis\Command;

use Ody\SwooleRedis\Storage\HyperLogLogStorage;
use Ody\SwooleRedis\Protocol\ResponseFormatter;

/**
 * Implements Redis HyperLogLog commands
 */
class HyperLogLogCommands implements CommandInterface
{
    private HyperLogLogStorage $storage;
    private ResponseFormatter $formatter;

    public function __construct(
        HyperLogLogStorage $storage,
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
            case 'PFADD':
                return $this->pfAdd($args);

            case 'PFCOUNT':
                return $this->pfCount($args);

            case 'PFMERGE':
                return $this->pfMerge($args);

            default:
                return $this->formatter->error("Unknown command '{$command}'");
        }
    }

    /**
     * Implement PFADD command
     */
    private function pfAdd(array $args): string
    {
        if (count($args) < 2) {
            return $this->formatter->error("Wrong number of arguments for PFADD command");
        }

        $key = $args[0];
        $elements = array_slice($args, 1);

        $changed = 0;
        foreach ($elements as $element) {
            try {
                $result = $this->storage->pfAdd($key, $element);
                if ($result === 1) {
                    $changed = 1;
                }
            } catch (\Exception $e) {
                return $this->formatter->error($e->getMessage());
            }
        }

        return $this->formatter->integer($changed);
    }

    /**
     * Implement PFCOUNT command
     */
    private function pfCount(array $args): string
    {
        if (count($args) < 1) {
            return $this->formatter->error("Wrong number of arguments for PFCOUNT command");
        }

        if (count($args) === 1) {
            // Single key
            try {
                $count = $this->storage->pfCount($args[0]);
                return $this->formatter->integer($count);
            } catch (\Exception $e) {
                return $this->formatter->error($e->getMessage());
            }
        } else {
            // Multiple keys - we need to handle this differently than before
            // Rather than creating a temporary key, we'll calculate this on the fly
            try {
                $count = $this->storage->pfCountMultiple($args);
                return $this->formatter->integer($count);
            } catch (\Exception $e) {
                return $this->formatter->error($e->getMessage());
            }
        }
    }

    /**
     * Implement PFMERGE command
     */
    private function pfMerge(array $args): string
    {
        if (count($args) < 2) {
            return $this->formatter->error("Wrong number of arguments for PFMERGE command");
        }

        $destKey = $args[0];
        $sourceKeys = array_slice($args, 1);

        try {
            $result = $this->storage->pfMerge($destKey, $sourceKeys);
            if ($result) {
                return $this->formatter->simpleString("OK");
            } else {
                return $this->formatter->error("Error merging HyperLogLogs");
            }
        } catch (\Exception $e) {
            return $this->formatter->error($e->getMessage());
        }
    }
}