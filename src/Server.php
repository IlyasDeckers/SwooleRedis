<?php

namespace Ody\SwooleRedis;

use Ody\SwooleRedis\Command\CommandFactory;
use Ody\SwooleRedis\Command\PubSubCommands;
use Ody\SwooleRedis\Command\ServerAdminCommands;
use Ody\SwooleRedis\Persistence\PersistenceManager;
use Ody\SwooleRedis\Protocol\CommandParser;
use Ody\SwooleRedis\Protocol\ResponseFormatter;
use Ody\SwooleRedis\Storage\KeyExpiry;
use Ody\SwooleRedis\Storage\StringStorage;
use Ody\SwooleRedis\Storage\HashStorage;
use Ody\SwooleRedis\Storage\ListStorage;
use Swoole\Server as SwooleServer;

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
    private PersistenceManager $persistenceManager;
    private array $subscribers = [];
    private string $host;
    private int $port;
    private array $config;

    public function __construct(string $host = '127.0.0.1', int $port = 6380, array $config = [])
    {
        $this->host = $host;
        $this->port = $port;
        $this->config = $config;
    }

    private function initialize(): void
    {
        // Initialize components
        $this->stringStorage = new StringStorage(1024);
        $this->hashStorage = new HashStorage(1024);
        $this->listStorage = new ListStorage(1024);
        $this->keyExpiry = new KeyExpiry(1024);
        $this->commandParser = new CommandParser();
        $this->responseFormatter = new ResponseFormatter();

        // Initialize persistence manager
        $persistenceConfig = [
            'dir' => $this->config['persistence_dir'] ?? '/tmp',
            'rdb_enabled' => $this->config['rdb_enabled'] ?? true,
            'rdb_filename' => $this->config['rdb_filename'] ?? 'dump.rdb',
            'rdb_save_seconds' => $this->config['rdb_save_seconds'] ?? 900,
            'rdb_min_changes' => $this->config['rdb_min_changes'] ?? 1,
            'aof_enabled' => $this->config['aof_enabled'] ?? false,
            'aof_filename' => $this->config['aof_filename'] ?? 'appendonly.aof',
            'aof_fsync' => $this->config['aof_fsync'] ?? 'everysec',
            'aof_rewrite_seconds' => $this->config['aof_rewrite_seconds'] ?? 3600,
            'aof_rewrite_min_size' => $this->config['aof_rewrite_min_size'] ?? 64 * 1024 * 1024,
        ];

        $this->persistenceManager = new PersistenceManager($persistenceConfig);
        $this->persistenceManager->setStorageRepositories(
            $this->stringStorage,
            $this->hashStorage,
            $this->listStorage,
            $this->keyExpiry
        );

        // Initialize command factory with persistence manager
        $this->commandFactory = new CommandFactory(
            $this->stringStorage,
            $this->hashStorage,
            $this->listStorage,
            $this->keyExpiry,
            $this->subscribers,
            $this->responseFormatter,
            $this->persistenceManager
        );

        // Load data from persistence storage
        $this->persistenceManager->loadData();
    }

    /**
     * Stop the server gracefully
     */
    public function stop(): void
    {
        static $stopping = false;

        // Prevent multiple calls
        if ($stopping) {
            return;
        }

        $stopping = true;
        echo "Stopping SwooleRedis server...\n";

        // Save any pending data
        if (isset($this->persistenceManager)) {
            $this->persistenceManager->shutdown();
        }

        // Close all connections and stop the server
        if (isset($this->server)) {
            $this->server->shutdown();
        }

        echo "SwooleRedis server stopped gracefully\n";
    }

    /**
     * Set up signal handling for graceful shutdown
     */
    private function setupSignalHandling(): void
    {
        // Store reference to $this to use in closures
        $self = $this;

        // Use standard PHP pcntl signal handling
        if (extension_loaded('pcntl')) {
            // Handle SIGTERM (kill command)
            pcntl_signal(SIGTERM, function () use ($self) {
                echo "Received SIGTERM, shutting down...\n";
                $self->stop();
                exit(0);
            });

            // Handle SIGINT (Ctrl+C)
            pcntl_signal(SIGINT, function () use ($self) {
                echo "Received SIGINT (Ctrl+C), shutting down...\n";
                $self->stop();
                exit(0);
            });

            // Enable asynchronous signal handling
            pcntl_async_signals(true);

            echo "Signal handlers registered for graceful shutdown\n";
        } else {
            echo "Warning: pcntl extension not loaded\n";
            echo "CTRL+C handling may not work correctly\n";
        }

        // Register a shutdown function that doesn't use $this
        register_shutdown_function(function () use ($self) {
            echo "PHP shutdown function triggered\n";
            // Only stop if the server is initialized
            if (isset($self) && method_exists($self, 'stop')) {
                $self->stop();
            }
        });
    }

    public function start(): void
    {
        echo "SwooleRedis server starting on {$this->host}:{$this->port}\n";

        // Initialize components - moved from constructor
        $this->initialize();

        // Initialize Swoole server
        $this->server = new SwooleServer($this->host, $this->port, SWOOLE_BASE);
        $this->server->set([
            'worker_num' => swoole_cpu_num(),
            'max_conn' => 10000,
            'backlog' => 128,
        ]);

        // Set up event handlers
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
            if ($handler instanceof PubSubCommands) {
                $handler->setServer($server);
            }

            // For ServerAdmin commands, set the server instance
            if ($handler instanceof ServerAdminCommands) {
                $handler->setServer($this);
            }

            // Need to pass the original command name as the first argument
            $args = array_merge([$command['command']], $command['args']);
            $response = $handler->execute($fd, $args);

            // Log the command for persistence
            $this->persistenceManager->logWriteCommand($command['command'], $command['args']);

            $server->send($fd, $response);
        });

        $this->server->on('close', function ($server, $fd) {
            // Unsubscribe if the client was subscribed to any channels
            foreach ($this->subscribers as $channel => $subscribers) {
                if (($key = array_search($fd, $subscribers)) !== false) {
                    unset($this->subscribers[$channel][$key]);
                    if (empty($this->subscribers[$channel])) {
                        unset($this->subscribers[$channel]);
                    }
                }
            }
            echo "Client disconnected: {$fd}\n";
        });

        // Set up signal handling for graceful shutdown
        $this->setupSignalHandling();

        // Start expiration checker and persistence timers after server starts
        $this->server->on('start', function ($server) {
            // Set up timer for key expiration
            \Swoole\Timer::tick(1000, function () {
                $this->keyExpiry->checkExpirations($this->stringStorage);
            });

            // Start persistence timers
            $this->persistenceManager->startPersistenceTimers();

            echo "Timers initialized\n";
        });

        // Start the server
        $this->server->start();
    }
}