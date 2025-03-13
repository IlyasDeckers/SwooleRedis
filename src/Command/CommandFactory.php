<?php

namespace Ody\SwooleRedis\Command;

use Ody\SwooleRedis\Persistence\PersistenceManager;
use Ody\SwooleRedis\Storage\StringStorage;
use Ody\SwooleRedis\Storage\HashStorage;
use Ody\SwooleRedis\Storage\ListStorage;
use Ody\SwooleRedis\Storage\SetStorage;
use Ody\SwooleRedis\Storage\SortedSetStorage;
use Ody\SwooleRedis\Storage\BitMapStorage;
use Ody\SwooleRedis\Storage\HyperLogLogStorage;
use Ody\SwooleRedis\Storage\KeyExpiry;
use Ody\SwooleRedis\Protocol\ResponseFormatter;

class CommandFactory
{
    private StringStorage $stringStorage;
    private HashStorage $hashStorage;
    private ListStorage $listStorage;
    private SetStorage $setStorage;
    private SortedSetStorage $sortedSetStorage;
    private BitMapStorage $bitMapStorage;
    private HyperLogLogStorage $hyperLogLogStorage;
    private KeyExpiry $keyExpiry;
    private array $subscribers;
    private array $clientTransactions;
    private ResponseFormatter $responseFormatter;

    private PersistenceManager $persistenceManager;

    private array $serverInfo = [];

    public function __construct(
        StringStorage $stringStorage,
        HashStorage $hashStorage,
        ListStorage $listStorage,
        KeyExpiry $keyExpiry,
        array &$subscribers,
        ResponseFormatter $responseFormatter,
        PersistenceManager $persistenceManager
    ) {
        $this->stringStorage = $stringStorage;
        $this->hashStorage = $hashStorage;
        $this->listStorage = $listStorage;
        $this->keyExpiry = $keyExpiry;
        $this->subscribers = &$subscribers;
        $this->responseFormatter = $responseFormatter;
        $this->persistenceManager = $persistenceManager;
        $this->clientTransactions = [];

        // Initialize additional storage
        $this->setStorage = new SetStorage();
        $this->sortedSetStorage = new SortedSetStorage();
        $this->bitMapStorage = new BitMapStorage($stringStorage);
        $this->hyperLogLogStorage = new HyperLogLogStorage($stringStorage);

        // Initialize server info
        $this->serverInfo = [
            'start_time' => time(),
            'connections' => 0,
            'commands' => 0,
            'ops_per_sec' => 0,
            'expired_keys' => 0,
            'keyspace_hits' => 0,
            'keyspace_misses' => 0,
        ];
    }

    public function create(string $commandName): CommandInterface
    {
        // We need to pass the original command name to the handler classes
        $originalCommand = $commandName;
        $commandName = strtoupper($commandName);

        // Update command stats
        $this->updateServerStats('commands');

        // Check for MULTI/EXEC/DISCARD/WATCH transaction commands first
        if (in_array($commandName, ['MULTI', 'EXEC', 'DISCARD', 'WATCH', 'UNWATCH'])) {
            return new TransactionCommands(
                $this->responseFormatter,
                $this->clientTransactions,
                $this
            );
        }

        switch ($commandName) {
            // String commands
            case 'PING':
            case 'GET':
            case 'SET':
                return new StringCommands(
                    $this->stringStorage,
                    $this->keyExpiry,
                    $this->responseFormatter
                );

            // Key commands
            case 'DEL':
            case 'EXPIRE':
            case 'TTL':
                return new KeyCommands(
                    $this->stringStorage,
                    $this->keyExpiry,
                    $this->responseFormatter
                );

            // List commands
            case 'LPUSH':
            case 'LPOP':
            case 'RPUSH':
            case 'RPOP':
            case 'LLEN':
            case 'LRANGE':
                return new ListCommands(
                    $this->listStorage,
                    $this->responseFormatter
                );

            // Hash commands
            case 'HSET':
            case 'HGET':
            case 'HDEL':
            case 'HKEYS':
            case 'HVALS':
            case 'HGETALL':
                return new HashCommands(
                    $this->hashStorage,
                    $this->responseFormatter
                );

            // Set commands
            case 'SADD':
            case 'SCARD':
            case 'SDIFF':
            case 'SINTER':
            case 'SISMEMBER':
            case 'SMEMBERS':
            case 'SMOVE':
            case 'SPOP':
            case 'SRANDMEMBER':
            case 'SREM':
            case 'SUNION':
                return new SetCommands(
                    $this->setStorage,
                    $this->responseFormatter
                );

            // Sorted Set commands
            case 'ZADD':
            case 'ZCARD':
            case 'ZCOUNT':
            case 'ZINCRBY':
            case 'ZRANGE':
            case 'ZRANGEBYSCORE':
            case 'ZREM':
            case 'ZREVRANGE':
            case 'ZSCORE':
                return new SortedSetCommands(
                    $this->sortedSetStorage,
                    $this->responseFormatter
                );

            // Bitmap commands
            case 'GETBIT':
            case 'SETBIT':
            case 'BITCOUNT':
            case 'BITOP':
            case 'BITPOS':
                return new BitMapCommands(
                    $this->bitMapStorage,
                    $this->responseFormatter
                );

            // HyperLogLog commands
            case 'PFADD':
            case 'PFCOUNT':
            case 'PFMERGE':
                return new HyperLogLogCommands(
                    $this->hyperLogLogStorage,
                    $this->responseFormatter
                );

            // PubSub commands
            case 'PUBLISH':
            case 'SUBSCRIBE':
            case 'UNSUBSCRIBE':
            case 'PUBSUB':
                $pubSubCommands = new PubSubCommands(
                    $this->subscribers,
                    $this->responseFormatter
                );
                return $pubSubCommands;

            // Server admin commands
            case 'SAVE':
            case 'BGSAVE':
            case 'LASTSAVE':
            case 'INFO':
            case 'SHUTDOWN':
                return new ServerAdminCommands(
                    $this->responseFormatter,
                    $this->persistenceManager,
                    $this->serverInfo
                );

            default:
                return new UnknownCommand($this->responseFormatter, $originalCommand);
        }
    }

    public function updateServerStats(string $type, $value = 1): void
    {
        if (isset($this->serverInfo[$type])) {
            $this->serverInfo[$type] += $value;
        }
    }
}