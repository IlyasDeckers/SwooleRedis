<?php

namespace Ody\SwooleRedis;

use Ody\SwooleRedis\Command\CommandFactory;
use Ody\SwooleRedis\Protocol\CommandParser;
use Ody\SwooleRedis\Protocol\ResponseFormatter;
use Ody\SwooleRedis\Storage\KeyExpiry;
use Ody\SwooleRedis\Storage\StringStorage;
use Ody\SwooleRedis\Storage\HashStorage;
use Ody\SwooleRedis\Storage\ListStorage;
use Swoole\Server as SwooleServer;
use Swoole\Timer;

class Server
{
    private SwooleServer $server;
    private CommandParser $commandParser;
    private CommandFactory $commandFactory;
    private ResponseFormatter $responseFormatter;
    private StringStorage $stringStorage;
    private HashStorage $hashStorage;
    private ListStorage $listStorage;
    private KeyExpiry $keyExpiry;
    private array $subscribers = [];
    private string $host;
    private int $port;

    public function __construct(string $host = '127.0.0.1', int $port = 6380)
    {
        $this->host = $host;
        $this->port = $port;

        // Initialize components
        $this->stringStorage = new StringStorage();
        $this->hashStorage = new HashStorage();
        $this->listStorage = new ListStorage();
        $this->keyExpiry = new KeyExpiry();
        $this->commandParser = new CommandParser();
        $this->responseFormatter = new ResponseFormatter();
        $this->commandFactory = new CommandFactory(
            $this->stringStorage,
            $this->hashStorage,
            $this->listStorage,
            $this->keyExpiry,
            $this->subscribers,
            $this->responseFormatter
        );

        // Initialize Swoole server
        $this->server = new SwooleServer($host, $port, SWOOLE_BASE);
        $this->server->set([
            'worker_num' => swoole_cpu_num(),
            'max_conn' => 10000,
            'backlog' => 128,
            'log_level' => SWOOLE_LOG_INFO, // Add logging for debugging
            'log_file' => '/tmp/swoole_redis.log',
            'heartbeat_check_interval' => 60,
            'heartbeat_idle_time' => 120,
        ]);

        $this->setupEventHandlers();
        $this->startExpirationChecker();
    }

    private function setupEventHandlers(): void
    {
        $this->server->on('connect', function ($server, $fd) {
            echo "Client connected: {$fd}\n";
        });

        $this->server->on('receive', function ($server, $fd, $reactorId, $data) {
            $command = $this->commandParser->parse($data);

            if (!$command) {
                $server->send($fd, $this->responseFormatter->error("Invalid command format"));
                return;
            }

            $handler = $this->commandFactory->create($command['command']);

            // For PubSub commands, we need to set the server instance
            if ($handler instanceof Command\PubSubCommands) {
                $handler->setServer($server);
            }

            // Need to pass the original command name as the first argument
            $args = array_merge([$command['command']], $command['args']);
            $response = $handler->execute($fd, $args);
            $server->send($fd, $response);
        });

        $this->server->on('close', function ($server, $fd) {
            // Unsubscribe if the client was subscribed to any channels
            foreach ($this->subscribers as $channel => $subscribers) {
                if (($key = array_search($fd, $subscribers)) !== false) {
                    unset($this->subscribers[$channel][$key]);
                    // Remove the channel if no subscribers left
                    if (empty($this->subscribers[$channel])) {
                        unset($this->subscribers[$channel]);
                    }
                }
            }
            echo "Client disconnected: {$fd}\n";
        });
    }

    private function startExpirationChecker(): void
    {
        Timer::tick(1000, function () {
            $this->keyExpiry->checkExpirations($this->stringStorage);
        });
    }

    public function start(): void
    {
        echo "SwooleRedis server starting on {$this->host}:{$this->port}\n";
        $this->server->start();
    }
}