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
    // Buffer for incomplete RESP data
    private array $clientBuffers = [];

    public function __construct(string $host = '127.0.0.1', int $port = 6380, array $config = [])
    {
        $this->host = $host;
        $this->port = $port;
        $this->config = $config;
    }

    private function initialize(): void
    {
        // Get memory configuration
        $stringTableSize = $this->config['memory_string_table_size'] ?? 0;
        $hashTableSize = $this->config['memory_hash_table_size'] ?? 0;
        $listTableSize = $this->config['memory_list_table_size'] ?? 0;
        $expiryTableSize = $this->config['memory_expiry_table_size'] ?? 0;

        // Initialize components with configured sizes (or auto-detect if 0)
        $this->stringStorage = new StringStorage($stringTableSize);
        $this->hashStorage = new HashStorage($hashTableSize);
        $this->listStorage = new ListStorage($listTableSize);
        $this->keyExpiry = new KeyExpiry($expiryTableSize);
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
     * Process a complete RESP command
     *
     * @param int $fd Client connection ID
     * @param string $data Command data
     */
    private function processCommand(int $fd, string $data): void
    {
        $command = $this->commandParser->parse($data);

        if (!$command) {
            $this->server->send($fd, $this->responseFormatter->error("Invalid command format"));
            return;
        }

        $handler = $this->commandFactory->create($command['command']);

        // For PubSub commands, we need to set the server instance
        if ($handler instanceof PubSubCommands) {
            $handler->setServer($this->server);
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

        $this->server->send($fd, $response);
    }

    /**
     * Check if a RESP message is complete
     *
     * @param string $data The data to check
     * @return bool True if the data forms a complete RESP message
     */
    private function isCompleteRespMessage(string $data): bool
    {
        // If it doesn't start with a RESP type indicator, use the old parser
        if (empty($data) || !in_array($data[0], ['+', '-', ':', '$', '*'])) {
            return true;
        }

        try {
            // Simple validation for complete RESP message
            if ($data[0] === '*') { // Array
                $lines = explode("\r\n", $data);
                if (count($lines) < 2) return false;

                $count = (int)substr($lines[0], 1);
                if ($count < 0) return true; // Null array

                // Count bulk strings and their contents
                $i = 1;
                for ($j = 0; $j < $count; $j++) {
                    if ($i >= count($lines)) return false;

                    if (substr($lines[$i], 0, 1) !== '$') return false;
                    $bulkLen = (int)substr($lines[$i], 1);

                    if ($bulkLen < 0) {
                        // Null bulk string
                        $i++;
                    } else {
                        // Regular bulk string
                        $i += 2; // Skip length line and content line
                    }
                }

                return $i <= count($lines);
            } else if ($data[0] === '$') { // Bulk String
                $lines = explode("\r\n", $data, 3);
                if (count($lines) < 2) return false;

                $length = (int)substr($lines[0], 1);
                if ($length < 0) return true; // Null bulk string

                return strlen($data) >= strlen($lines[0]) + 2 + $length + 2;
            } else {
                // Simple string, error, or integer - just check for \r\n
                return strpos($data, "\r\n") !== false;
            }
        } catch (\Throwable $e) {
            // If any error occurs, consider the message incomplete
            return false;
        }
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

        // Get Swoole server configuration
        $workerNum = $this->config['worker_num'] ?? swoole_cpu_num();
        $maxConn = $this->config['max_conn'] ?? 10000;
        $backlog = $this->config['backlog'] ?? 128;

        $this->server->set([
            'worker_num' => $workerNum,
            'max_conn' => $maxConn,
            'backlog' => $backlog,
            'open_eof_check' => false, // We'll handle message boundaries ourselves
            'open_eof_split' => false,
            'package_max_length' => 16 * 1024 * 1024, // 16MB max package
        ]);

        // Set up event handlers
        $this->server->on('connect', function ($server, $fd) {
            // Initialize buffer for this connection
            $this->clientBuffers[$fd] = '';
            echo "Client connected: {$fd}\n";
        });

        $this->server->on('receive', function ($server, $fd, $reactorId, $data) {
            // Append new data to the client's buffer
            if (!isset($this->clientBuffers[$fd])) {
                $this->clientBuffers[$fd] = '';
            }

            $this->clientBuffers[$fd] .= $data;

            // Process as many complete commands as possible
            while (!empty($this->clientBuffers[$fd])) {
                if ($this->isCompleteRespMessage($this->clientBuffers[$fd])) {
                    $command = $this->clientBuffers[$fd];
                    $this->clientBuffers[$fd] = '';
                    $this->processCommand($fd, $command);
                } else {
                    // Wait for more data
                    break;
                }
            }
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

            // Clean up buffer
            unset($this->clientBuffers[$fd]);

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

            // Log memory usage
            $memory = memory_get_usage() / 1024 / 1024;
            echo "Current memory usage: " . round($memory, 2) . " MB\n";
            echo "RESP protocol support enabled\n";
            echo "Timers initialized\n";
        });

        // Start the server
        $this->server->start();
    }
}