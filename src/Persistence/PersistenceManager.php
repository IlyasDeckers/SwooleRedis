<?php

namespace Ody\SwooleRedis\Persistence;

use Ody\SwooleRedis\Storage\StringStorage;
use Ody\SwooleRedis\Storage\HashStorage;
use Ody\SwooleRedis\Storage\ListStorage;
use Ody\SwooleRedis\Storage\KeyExpiry;
use Swoole\Timer;

/**
 * Manager class for handling persistence operations
 */
class PersistenceManager
{
    private StringStorage $stringStorage;
    private HashStorage $hashStorage;
    private ListStorage $listStorage;
    private KeyExpiry $keyExpiry;
    private ?RdbPersistence $rdbPersistence = null;
    private ?AofPersistence $aofPersistence = null;
    private array $config;
    private ?int $rdbSaveTimerId = null;
    private ?int $aofRewriteTimerId = null;
    private int $lastSaveTime = 0;
    private int $changesCount = 0;

    /**
     * Create a new PersistenceManager
     *
     * @param array $config Persistence configuration
     */
    public function __construct(array $config = [])
    {
        // Default configuration
        $this->config = array_merge([
            'dir' => '/tmp',
            'rdb_enabled' => true,
            'rdb_filename' => 'dump.rdb',
            'rdb_save_seconds' => 900,  // 15 minutes
            'rdb_min_changes' => 1,     // At least 1 change
            'aof_enabled' => false,
            'aof_filename' => 'appendonly.aof',
            'aof_fsync' => 'everysec',  // 'always', 'everysec', or 'no'
            'aof_rewrite_seconds' => 3600, // 1 hour
            'aof_rewrite_min_size' => 64 * 1024 * 1024, // 64MB
        ], $config);
    }

    /**
     * Set the storage repositories
     */
    public function setStorageRepositories(
        StringStorage $stringStorage,
        HashStorage   $hashStorage,
        ListStorage   $listStorage,
        KeyExpiry     $keyExpiry
    ): void
    {
        $this->stringStorage = $stringStorage;
        $this->hashStorage = $hashStorage;
        $this->listStorage = $listStorage;
        $this->keyExpiry = $keyExpiry;

        // Initialize persistence mechanisms
        $this->initPersistence();
    }

    /**
     * Initialize persistence mechanisms based on configuration
     */
    private function initPersistence(): void
    {
        if ($this->config['rdb_enabled']) {
            $this->rdbPersistence = new RdbPersistence(
                $this->config['dir'],
                $this->config['rdb_filename']
            );

            $this->rdbPersistence->setStorageRepositories(
                $this->stringStorage,
                $this->hashStorage,
                $this->listStorage,
                $this->keyExpiry
            );
        }

        if ($this->config['aof_enabled']) {
            $this->aofPersistence = new AofPersistence(
                $this->config['dir'],
                $this->config['aof_filename']
            );

            $this->aofPersistence->setStorageRepositories(
                $this->stringStorage,
                $this->hashStorage,
                $this->listStorage,
                $this->keyExpiry
            );
        }
    }

    /**
     * Load data from persistence storage during server startup
     *
     * @return bool True if data was loaded successfully
     */
    public function loadData(): bool
    {
        $result = true;

        // If AOF is enabled, try loading that first since it's more up-to-date
        if ($this->aofPersistence !== null) {
            $aofExists = file_exists($this->config['dir'] . '/' . $this->config['aof_filename']);

            if ($aofExists) {
                $result = $this->aofPersistence->load();

                // If AOF load was successful, we don't need to load RDB
                if ($result) {
                    echo "Data loaded from AOF file\n";
                    return true;
                }
            }
        }

        // If AOF loading failed or isn't enabled, try RDB
        if ($this->rdbPersistence !== null) {
            $rdbExists = file_exists($this->config['dir'] . '/' . $this->config['rdb_filename']);

            if ($rdbExists) {
                $result = $this->rdbPersistence->load();
                if ($result) {
                    echo "Data loaded from RDB file\n";
                }
            }
        }

        // Note: We no longer start timers here
        // They will be started by the server after it fully starts

        return $result;
    }

    /**
     * Start timers for automatic persistence operations
     * This should be called after the server has started to avoid event loop conflicts
     */
    public function startPersistenceTimers(): void
    {
        // Set up RDB save timer
        if ($this->rdbPersistence !== null && $this->config['rdb_save_seconds'] > 0) {
            $this->rdbSaveTimerId = \Swoole\Timer::tick($this->config['rdb_save_seconds'] * 1000, function () {
                $this->backgroundSave();
            });
        }

        // Set up AOF rewrite timer
        if ($this->aofPersistence !== null && $this->config['aof_rewrite_seconds'] > 0) {
            $this->aofRewriteTimerId = \Swoole\Timer::tick($this->config['aof_rewrite_seconds'] * 1000, function () {
                $this->checkAndRewriteAof();
            });
        }

        // Set up AOF fsync timer if using 'everysec'
        if ($this->aofPersistence !== null && $this->config['aof_fsync'] === 'everysec') {
            \Swoole\Timer::tick(1000, function () {
                $this->aofPersistence->save();
            });
        }
    }

    /**
     * Called when a write command is executed
     * This logs commands to AOF and tracks changes for RDB
     *
     * @param string $command The command name
     * @param array $args The command arguments
     */
    public function logWriteCommand(string $command, array $args): void
    {
        // Only track write commands
        if ($this->isWriteCommand($command)) {
            // Increment changes count
            $this->changesCount++;

            // Log to AOF if enabled
            if ($this->aofPersistence !== null) {
                $this->aofPersistence->logCommand($command, $args);

                // If fsync is set to 'always', save immediately
                if ($this->config['aof_fsync'] === 'always') {
                    $this->aofPersistence->save();
                }
            }

            // Check if we need to do an auto-save for RDB
            if ($this->rdbPersistence !== null) {
                $timeSinceLastSave = time() - $this->lastSaveTime;

                if ($timeSinceLastSave >= $this->config['rdb_save_seconds'] &&
                    $this->changesCount >= $this->config['rdb_min_changes']) {
                    $this->backgroundSave();
                }
            }
        }
    }

    /**
     * Check if a command is a write command
     *
     * @param string $command The command name
     * @return bool True if the command modifies data
     */
    private function isWriteCommand(string $command): bool
    {
        $writeCommands = [
            'SET', 'DEL', 'EXPIRE',
            'HSET', 'HDEL',
            'LPUSH', 'RPUSH', 'LPOP', 'RPOP',
            // Add other write commands as they are implemented
        ];

        return in_array(strtoupper($command), $writeCommands);
    }

    /**
     * Perform an RDB save in the background
     *
     * @return bool True if save was successful
     */
    public function backgroundSave(): bool
    {
        if ($this->rdbPersistence === null) {
            return false;
        }

        echo "Starting background save...\n";
        $result = $this->rdbPersistence->save();

        if ($result) {
            $this->lastSaveTime = time();
            $this->changesCount = 0;
            echo "Background save completed successfully\n";
        } else {
            echo "Background save failed\n";
        }

        return $result;
    }

    /**
     * Check if AOF needs rewriting and perform if necessary
     *
     * @return bool True if rewrite was performed and successful
     */
    private function checkAndRewriteAof(): bool
    {
        if ($this->aofPersistence === null) {
            return false;
        }

        $aofFile = $this->config['dir'] . '/' . $this->config['aof_filename'];

        if (!file_exists($aofFile)) {
            return false;
        }

        $fileSize = filesize($aofFile);

        // Only rewrite if the file is larger than the minimum size
        if ($fileSize >= $this->config['aof_rewrite_min_size']) {
            echo "AOF file size ($fileSize bytes) exceeds threshold, starting rewrite...\n";
            return $this->aofPersistence->rewriteAof();
        }

        return false;
    }

    /**
     * Force save operations immediately
     *
     * @return bool True if saves were successful
     */
    public function forceSave(): bool
    {
        $result = true;

        if ($this->rdbPersistence !== null) {
            $rdbResult = $this->rdbPersistence->save();
            if ($rdbResult) {
                $this->lastSaveTime = time();
                $this->changesCount = 0;
            }
            $result = $result && $rdbResult;
        }

        if ($this->aofPersistence !== null) {
            $aofResult = $this->aofPersistence->save();
            $result = $result && $aofResult;
        }

        return $result;
    }

    /**
     * Shutdown persistence operations cleanly
     */
    public function shutdown(): void
    {
        // Save everything one last time
        $this->forceSave();

        // Close file handles
        if ($this->aofPersistence !== null) {
            $this->aofPersistence->closeAofFile();
        }

        // Clear timers
        if ($this->rdbSaveTimerId !== null) {
            Timer::clear($this->rdbSaveTimerId);
        }

        if ($this->aofRewriteTimerId !== null) {
            Timer::clear($this->aofRewriteTimerId);
        }
    }

    /**
     * Get the last save timestamp
     *
     * @return int Unix timestamp of last save
     */
    public function getLastSaveTime(): int
    {
        return $this->lastSaveTime;
    }

    /**
     * Get the number of changes since last save
     *
     * @return int Number of changes
     */
    public function getChangesCount(): int
    {
        return $this->changesCount;
    }

    /**
     * Check if AOF is enabled
     *
     * @return bool True if AOF is enabled
     */
    public function isAofEnabled(): bool
    {
        return $this->aofPersistence !== null && $this->config['aof_enabled'];
    }

    /**
     * Get the AOF filename with full path
     *
     * @return string AOF filename
     */
    public function getAofFilename(): string
    {
        return $this->config['dir'] . '/' . $this->config['aof_filename'];
    }
}