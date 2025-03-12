<?php

namespace Ody\SwooleRedis;

use Ody\SwooleRedis\Command\CommandFactory;
use Ody\SwooleRedis\Persistence\PersistenceManager;
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
    private PersistenceManager $persistenceManager;
    private StringStorage $stringStorage;
    private HashStorage $hashStorage;
    private ListStorage $listStorage;
    private KeyExpiry $keyExpiry;
    private array $subscribers = [];
    private string $host;
    private int $port;

    public function __construct(string $host = '127.0.0.1', int $port = 6380, array $config = [])
    {
        $this->host = $host;
        $this->port = $port;
        $this->config = $config;

        // Initialize components
        $this->stringStorage = new StringStorage();
        $this->hashStorage = new HashStorage();
        $this->listStorage = new ListStorage();
        $this->keyExpiry = new KeyExpiry();
        $this->commandParser = new CommandParser();
        $this->responseFormatter = new ResponseFormatter();

        // Initialize persistence manager
        $persistenceConfig = [
            'dir' => $config['persistence_dir'] ?? '/tmp',
            'rdb_enabled' => $config['rdb_enabled'] ?? true,
            'rdb_filename' => $config['rdb_filename'] ?? 'dump.rdb',
            'rdb_save_seconds' => $config['rdb_save_seconds'] ?? 900,
            'rdb_min_changes' => $config['rdb_min_changes'] ?? 1,
            'aof_enabled' => $config['aof_enabled'] ?? false,
            'aof_filename' => $config['aof_filename'] ?? 'appendonly.aof',
            'aof_fsync' => $config['aof_fsync'] ?? 'everysec',
            'aof_rewrite_seconds' => $config['aof_rewrite_seconds'] ?? 3600,
            'aof_rewrite_min_size' => $config['aof_rewrite_min_size'] ?? 64 * 1024 * 1024,
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

        // Initialize Swoole server
        $this->server = new SwooleServer($host, $port, SWOOLE_BASE);
        $this->server->set([
            'worker_num' => swoole_cpu_num(),
            'max_conn' => 10000,
            'backlog' => 128,
            'log_level' => SWOOLE_LOG_INFO,
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

            // Log the command for persistence
            $this->persistenceManager->logWriteCommand($command['command'], $command['args']);

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

        $this->server->on('shutdown', function () {
            echo "Server shutdown event triggered\n";
            // This event is too late for persistence as the server is already stopping
            // but we can do any final cleanup here
        });

        // Handle Swoole worker stop event
        $this->server->on('WorkerStop', function ($server, $workerId) {
            echo "Worker {$workerId} is stopping...\n";
            // If needed, we could perform per-worker cleanup
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

        // Load data from persistence storage
        $this->persistenceManager->loadData();

        // Set up signal handling for graceful shutdown
        $this->setupSignalHandling();

        // Start the server
        $this->server->start();
    }

    /**
     * Stop the server gracefully
     */
    public function stop(): void
    {
        echo "Stopping SwooleRedis server...\n";

        // Save any pending data
        $this->persistenceManager->shutdown();

        // Close all connections and stop the server
        $this->server->shutdown();

        echo "SwooleRedis server stopped gracefully\n";
    }

    /**
     * Set up signal handling for graceful shutdown
     */
    private function setupSignalHandling(): void
    {
        // Use Swoole Process::signal for signal handling
        if (extension_loaded('pcntl') && method_exists('Swoole\\Process', 'signal')) {
            // Handle SIGTERM (kill command)
            \Swoole\Process::signal(SIGTERM, function () {
                echo "Received SIGTERM, shutting down...\n";
                $this->stop();
                exit(0);
            });

            // Handle SIGINT (Ctrl+C)
            \Swoole\Process::signal(SIGINT, function () {
                echo "Received SIGINT (Ctrl+C), shutting down...\n";
                $this->stop();
                exit(0);
            });

            echo "Signal handlers registered for graceful shutdown\n";
        } else {
            echo "Warning: pcntl extension not loaded or Swoole\\Process::signal not available\n";
            echo "CTRL+C handling may not work correctly\n";
        }
    }
}

// Register a shutdown function as a last resort
register_shutdown_function(function () {
    echo "PHP shutdown function triggered\n";
    // Force persistence shutdown if it wasn't already done
    if (isset($this->persistenceManager)) {
        $this->persistenceManager->shutdown();
    }
});