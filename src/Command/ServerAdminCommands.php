<?php

namespace Ody\SwooleRedis\Command;

use Ody\SwooleRedis\Protocol\ResponseFormatter;
use Ody\SwooleRedis\Persistence\PersistenceManager;
use Ody\SwooleRedis\Server;

/**
 * Implements Redis server admin commands
 */
class ServerAdminCommands implements CommandInterface
{
    private ResponseFormatter $formatter;
    private PersistenceManager $persistenceManager;
    private array $serverInfo;
    private ?Server $server = null;

    public function __construct(
        ResponseFormatter $formatter,
        PersistenceManager $persistenceManager,
        array $serverInfo = []
    ) {
        $this->formatter = $formatter;
        $this->persistenceManager = $persistenceManager;
        $this->serverInfo = $serverInfo;
    }

    /**
     * Set the server instance for shutdown operations
     */
    public function setServer(Server $server): void
    {
        $this->server = $server;
    }

    /**
     * {@inheritdoc}
     */
    public function execute(int $clientId, array $args): string
    {
        if (empty($args)) {
            return $this->formatter->error("Wrong number of arguments");
        }

        $command = strtoupper(array_shift($args));

        switch ($command) {
            case 'SAVE':
                return $this->save();

            case 'BGSAVE':
                return $this->bgSave();

            case 'LASTSAVE':
                return $this->lastSave();

            case 'INFO':
                return $this->info($args);

            case 'SHUTDOWN':
                return $this->shutdown($args);

            default:
                return $this->formatter->error("Unknown command '{$command}'");
        }
    }

    /**
     * Implement SAVE command - synchronous save
     */
    private function save(): string
    {
        $result = $this->persistenceManager->forceSave();

        if ($result) {
            return $this->formatter->simpleString("OK");
        } else {
            return $this->formatter->error("Background save failed");
        }
    }

    /**
     * Implement BGSAVE command - asynchronous save
     */
    private function bgSave(): string
    {
        // In Swoole, we don't have true forking, so we just do an asynchronous save
        go(function () {
            $this->persistenceManager->backgroundSave();
        });

        return $this->formatter->simpleString("Background saving started");
    }

    /**
     * Implement LASTSAVE command - return timestamp of last save
     */
    private function lastSave(): string
    {
        $timestamp = $this->persistenceManager->getLastSaveTime();
        return $this->formatter->integer($timestamp);
    }

    /**
     * Implement INFO command - return server information
     */
    private function info(array $args = []): string
    {
        $section = empty($args) ? 'all' : strtolower($args[0]);

        $info = [
            'server' => [
                'swoole_redis_version' => '1.0.0',
                'swoole_version' => SWOOLE_VERSION,
                'uptime_in_seconds' => time() - ($this->serverInfo['start_time'] ?? time()),
                'uptime_in_days' => intdiv(time() - ($this->serverInfo['start_time'] ?? time()), 86400),
                'hz' => 10,
                'configured_hz' => 10,
                'os' => PHP_OS,
                'arch_bits' => PHP_INT_SIZE * 8,
                'process_id' => getmypid(),
                'tcp_port' => $this->serverInfo['port'] ?? 6380,
                'server_time' => time(),
            ],
            'persistence' => [
                'loading' => 0,
                'rdb_changes_since_last_save' => $this->persistenceManager->getChangesCount(),
                'rdb_last_save_time' => $this->persistenceManager->getLastSaveTime(),
                'rdb_last_save_status' => 'ok',
                'aof_enabled' => (int)$this->persistenceManager->isAofEnabled(),
                'aof_rewrite_in_progress' => 0,
                'aof_last_rewrite_time_sec' => -1,
                'aof_current_size' => $this->getAofSize(),
                'aof_pending_rewrite' => 0,
            ],
            'stats' => [
                'total_connections_received' => $this->serverInfo['connections'] ?? 0,
                'total_commands_processed' => $this->serverInfo['commands'] ?? 0,
                'instantaneous_ops_per_sec' => $this->serverInfo['ops_per_sec'] ?? 0,
                'rejected_connections' => 0,
                'expired_keys' => $this->serverInfo['expired_keys'] ?? 0,
                'evicted_keys' => 0,
                'keyspace_hits' => $this->serverInfo['keyspace_hits'] ?? 0,
                'keyspace_misses' => $this->serverInfo['keyspace_misses'] ?? 0,
            ],
            'memory' => [
                'swoole_used_memory' => memory_get_usage(),
                'swoole_used_memory_peak' => memory_get_peak_usage(),
                'total_system_memory' => $this->getTotalSystemMemory(),
                'used_memory_lua' => 0,
                'used_memory_scripts' => 0,
            ],
        ];

        $output = '';

        if ($section === 'all' || $section === 'default') {
            foreach ($info as $sectionName => $sectionData) {
                $output .= "# $sectionName\r\n";

                foreach ($sectionData as $key => $value) {
                    $output .= "$key:$value\r\n";
                }

                $output .= "\r\n";
            }
        } else if (isset($info[$section])) {
            $output .= "# $section\r\n";

            foreach ($info[$section] as $key => $value) {
                $output .= "$key:$value\r\n";
            }
        } else {
            return $this->formatter->error("Invalid info section: $section");
        }

        return $this->formatter->bulkString($output);
    }

    /**
     * Implement SHUTDOWN command
     * Saves data and shuts down the server
     */
    private function shutdown(array $args = []): string
    {
        $saveOption = '';

        if (!empty($args)) {
            $saveOption = strtoupper($args[0]);
        }

        // Check if we should save before shutdown
        $saveBeforeShutdown = true;

        if ($saveOption === 'NOSAVE') {
            $saveBeforeShutdown = false;
        } else if ($saveOption === 'SAVE') {
            $saveBeforeShutdown = true;
        }

        // Send OK to the client before shutting down
        $response = $this->formatter->simpleString("OK - Shutting down");

        // Use go to shut down after responding to the client
        go(function () use ($saveBeforeShutdown) {
            // Sleep for a moment to allow the response to be sent
            usleep(100000); // 100ms

            // Save if requested
            if ($saveBeforeShutdown) {
                echo "Saving data before shutdown...\n";
                $this->persistenceManager->forceSave();
            }

            // Shut down the server if we have a reference
            if ($this->server !== null) {
                echo "Shutting down server via SHUTDOWN command...\n";
                $this->server->stop();
            } else {
                echo "No server reference available, terminating process...\n";
                exit(0);
            }
        });

        return $response;
    }

    /**
     * Get total system memory in bytes
     */
    private function getTotalSystemMemory(): int
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $memInfo = file_get_contents('/proc/meminfo');
            if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $memInfo, $matches)) {
                return (int)$matches[1] * 1024;
            }
        }

        return 0;
    }

    /**
     * Get AOF file size
     */
    private function getAofSize(): int
    {
        $filename = $this->persistenceManager->getAofFilename();

        if (file_exists($filename)) {
            return filesize($filename);
        }

        return 0;
    }
}