<?php

namespace Ody\SwooleRedis\Command;

use Ody\SwooleRedis\Persistence\PersistenceManager;
use Ody\SwooleRedis\Storage\StringStorage;
use Ody\SwooleRedis\Storage\HashStorage;
use Ody\SwooleRedis\Storage\ListStorage;
use Ody\SwooleRedis\Storage\KeyExpiry;
use Ody\SwooleRedis\Protocol\ResponseFormatter;

class CommandFactory
{
    private StringStorage $stringStorage;
    private HashStorage $hashStorage;
    private ListStorage $listStorage;
    private KeyExpiry $keyExpiry;
    private array $subscribers;
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

/**
 * A fallback command handler for unknown commands
 */
class UnknownCommand implements CommandInterface
{
    private ResponseFormatter $responseFormatter;
    private string $commandName;

    public function __construct(ResponseFormatter $responseFormatter, string $commandName)
    {
        $this->responseFormatter = $responseFormatter;
        $this->commandName = $commandName;
    }

    public function execute(int $clientId, array $args): string
    {
        return $this->responseFormatter->error("Unknown command '{$this->commandName}'");
    }
}