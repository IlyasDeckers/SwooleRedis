<?php

namespace Ody\SwooleRedis\Persistence;

use Ody\SwooleRedis\Storage\StringStorage;
use Ody\SwooleRedis\Storage\HashStorage;
use Ody\SwooleRedis\Storage\ListStorage;
use Ody\SwooleRedis\Storage\KeyExpiry;
use Ody\SwooleRedis\Protocol\CommandParser;

/**
 * AOF persistence implementation
 * Records all write commands to be replayed on startup
 */
class AofPersistence implements PersistenceInterface
{
    private StringStorage $stringStorage;
    private HashStorage $hashStorage;
    private ListStorage $listStorage;
    private KeyExpiry $keyExpiry;
    private CommandParser $commandParser;
    private string $aofFilename;
    private string $dir;
    private $fileHandle = null;

    /**
     * @param string $dir Directory to store AOF files
     * @param string $aofFilename Filename for the AOF file
     */
    public function __construct(
        string $dir = '/tmp',
        string $aofFilename = 'appendonly.aof',
        CommandParser $commandParser = null
    ) {
        $this->dir = rtrim($dir, '/');
        $this->aofFilename = $aofFilename;
        $this->commandParser = $commandParser ?? new CommandParser();
    }

    /**
     * Set the storage repositories
     */
    public function setStorageRepositories(
        StringStorage $stringStorage,
        HashStorage $hashStorage,
        ListStorage $listStorage,
        KeyExpiry $keyExpiry
    ): void {
        $this->stringStorage = $stringStorage;
        $this->hashStorage = $hashStorage;
        $this->listStorage = $listStorage;
        $this->keyExpiry = $keyExpiry;
    }

    /**
     * Save a command to the AOF file
     * This should be called for every write command
     *
     * @param string $command The command name
     * @param array $args The command arguments
     * @return bool True if the command was written successfully
     */
    public function logCommand(string $command, array $args): bool
    {
        if ($this->fileHandle === null) {
            $this->openAofFile();
        }

        if ($this->fileHandle === false) {
            return false;
        }

        $timestamp = microtime(true);
        $commandLine = $this->formatCommandLine($command, $args);
        $logLine = "$timestamp $commandLine\n";

        return fwrite($this->fileHandle, $logLine) !== false;
    }

    /**
     * Open the AOF file for appending
     */
    private function openAofFile(): void
    {
        $filename = $this->dir . '/' . $this->aofFilename;
        $this->fileHandle = @fopen($filename, 'a');

        if ($this->fileHandle === false) {
            echo "Error opening AOF file for writing: $filename\n";
        }
    }

    /**
     * Close the AOF file
     */
    public function closeAofFile(): void
    {
        if ($this->fileHandle !== null && $this->fileHandle !== false) {
            fclose($this->fileHandle);
            $this->fileHandle = null;
        }
    }

    /**
     * Format a command for the AOF file
     *
     * @param string $command The command name
     * @param array $args The command arguments
     * @return string The formatted command line
     */
    private function formatCommandLine(string $command, array $args): string
    {
        $parts = [strtoupper($command)];

        foreach ($args as $arg) {
            // Quote arguments that contain spaces
            if (strpos($arg, ' ') !== false) {
                $parts[] = '"' . str_replace('"', '\"', $arg) . '"';
            } else {
                $parts[] = $arg;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Save the current dataset (not applicable for AOF)
     * This is a no-op for AOF which logs incrementally
     */
    public function save(): bool
    {
        // For AOF, we just need to flush the file buffer
        if ($this->fileHandle !== null && $this->fileHandle !== false) {
            return fflush($this->fileHandle);
        }

        return true;
    }

    /**
     * Load dataset by replaying AOF commands
     */
    public function load(): bool
    {
        $filename = $this->dir . '/' . $this->aofFilename;

        if (!file_exists($filename)) {
            echo "No AOF file found at: $filename\n";
            return false;
        }

        $file = @fopen($filename, 'r');

        if ($file === false) {
            echo "Error opening AOF file for reading: $filename\n";
            return false;
        }

        echo "Loading commands from AOF file: $filename\n";
        $lineNumber = 0;
        $commandCount = 0;

        while (($line = fgets($file)) !== false) {
            $lineNumber++;

            // Skip empty lines
            if (trim($line) === '') {
                continue;
            }

            // Parse line: timestamp command arg1 arg2...
            $parts = explode(' ', $line, 2);

            if (count($parts) < 2) {
                echo "Warning: Invalid AOF line format at line $lineNumber\n";
                continue;
            }

            $commandLine = trim($parts[1]);

            // Use the CommandParser to parse the command
            $parsedCommand = $this->commandParser->parse($commandLine);

            if ($parsedCommand === null) {
                echo "Warning: Failed to parse command at line $lineNumber: $commandLine\n";
                continue;
            }

            // Execute the command on the appropriate storage
            $this->executeCommand($parsedCommand['command'], $parsedCommand['args']);
            $commandCount++;
        }

        fclose($file);
        echo "AOF loaded successfully: processed $commandCount commands\n";

        // Open the file for appending new commands
        $this->openAofFile();

        return true;
    }

    /**
     * Execute a command against the storage repositories
     *
     * @param string $command Command name
     * @param array $args Command arguments
     */
    private function executeCommand(string $command, array $args): void
    {
        $command = strtoupper($command);

        switch ($command) {
            // String commands
            case 'SET':
                if (count($args) >= 2) {
                    $key = $args[0];
                    $value = $args[1];
                    $this->stringStorage->set($key, $value);

                    // Check for optional EX argument
                    if (isset($args[2]) && isset($args[3]) && strtoupper($args[2]) === 'EX') {
                        $seconds = (int)$args[3];
                        if ($seconds > 0) {
                            $this->keyExpiry->setExpiration($key, $seconds);
                        }
                    }
                }
                break;

            // Hash commands
            case 'HSET':
                if (count($args) >= 3) {
                    $key = $args[0];
                    $field = $args[1];
                    $value = $args[2];
                    $this->hashStorage->hSet($key, $field, $value);
                }
                break;

            case 'HDEL':
                if (count($args) >= 2) {
                    $key = $args[0];
                    $fields = array_slice($args, 1);

                    foreach ($fields as $field) {
                        $this->hashStorage->hDel($key, $field);
                    }
                }
                break;

            // List commands
            case 'LPUSH':
                if (count($args) >= 2) {
                    $key = $args[0];
                    $values = array_slice($args, 1);

                    foreach ($values as $value) {
                        $this->listStorage->lpush($key, $value);
                    }
                }
                break;

            case 'RPUSH':
                if (count($args) >= 2) {
                    $key = $args[0];
                    $values = array_slice($args, 1);

                    foreach ($values as $value) {
                        $this->listStorage->rpush($key, $value);
                    }
                }
                break;

            case 'LPOP':
                if (count($args) >= 1) {
                    $key = $args[0];
                    $this->listStorage->lpop($key);
                }
                break;

            case 'RPOP':
                if (count($args) >= 1) {
                    $key = $args[0];
                    $this->listStorage->rpop($key);
                }
                break;

            // Expiry commands
            case 'EXPIRE':
                if (count($args) >= 2) {
                    $key = $args[0];
                    $seconds = (int)$args[1];

                    if ($seconds > 0) {
                        $this->keyExpiry->setExpiration($key, $seconds);
                    } else {
                        // Delete the key if expire time is <= 0
                        $this->stringStorage->delete($key);
                        $this->hashStorage->delete($key);
                        $this->listStorage->delete($key);
                    }
                }
                break;

            // Key commands
            case 'DEL':
                if (count($args) >= 1) {
                    foreach ($args as $key) {
                        $this->stringStorage->delete($key);
                        $this->hashStorage->delete($key);
                        $this->listStorage->delete($key);
                        $this->keyExpiry->removeExpiration($key);
                    }
                }
                break;
        }
    }

    /**
     * Rewrite the AOF file to optimize size
     * This will remove redundant commands and replace with current state
     *
     * @return bool True if the rewrite was successful
     */
    public function rewriteAof(): bool
    {
        $tempFilename = $this->dir . '/' . uniqid('temp_') . '.aof';
        $tempHandle = @fopen($tempFilename, 'w');

        if ($tempHandle === false) {
            echo "Error opening temporary AOF file for writing: $tempFilename\n";
            return false;
        }

        $timestamp = microtime(true);

        // Write string data
        $stringData = $this->extractStringData();
        foreach ($stringData as $key => $value) {
            $commandLine = $this->formatCommandLine('SET', [$key, $value]);
            fwrite($tempHandle, "$timestamp $commandLine\n");

            // Write expiry if applicable
            $ttl = $this->keyExpiry->getTtl($key);
            if ($ttl > 0) {
                $commandLine = $this->formatCommandLine('EXPIRE', [$key, (string)$ttl]);
                fwrite($tempHandle, "$timestamp $commandLine\n");
            }
        }

        // Write hash data
        $hashData = $this->extractHashData();
        foreach ($hashData as $key => $hash) {
            foreach ($hash as $field => $value) {
                $commandLine = $this->formatCommandLine('HSET', [$key, $field, $value]);
                fwrite($tempHandle, "$timestamp $commandLine\n");
            }

            // Write expiry if applicable
            $ttl = $this->keyExpiry->getTtl($key);
            if ($ttl > 0) {
                $commandLine = $this->formatCommandLine('EXPIRE', [$key, (string)$ttl]);
                fwrite($tempHandle, "$timestamp $commandLine\n");
            }
        }

        // Write list data
        $listData = $this->extractListData();
        foreach ($listData as $key => $list) {
            if (!empty($list)) {
                $args = array_merge([$key], $list);
                $commandLine = $this->formatCommandLine('RPUSH', $args);
                fwrite($tempHandle, "$timestamp $commandLine\n");

                // Write expiry if applicable
                $ttl = $this->keyExpiry->getTtl($key);
                if ($ttl > 0) {
                    $commandLine = $this->formatCommandLine('EXPIRE', [$key, (string)$ttl]);
                    fwrite($tempHandle, "$timestamp $commandLine\n");
                }
            }
        }

        fclose($tempHandle);

        // Close the current AOF file
        $this->closeAofFile();

        // Atomically replace the old file with the new one
        $targetFile = $this->dir . '/' . $this->aofFilename;
        if (!rename($tempFilename, $targetFile)) {
            echo "Error replacing AOF file: $targetFile\n";
            @unlink($tempFilename);
            // Reopen the original file
            $this->openAofFile();
            return false;
        }

        // Reopen the new file
        $this->openAofFile();

        echo "AOF rewritten successfully to: $targetFile\n";
        return true;
    }

    /**
     * Extract all string data
     * Reused from RdbPersistence
     */
    private function extractStringData(): array
    {
        $data = [];
        $table = $this->stringStorage->getTable();

        foreach ($table as $key => $row) {
            // Skip keys that have expired
            if ($this->keyExpiry->isExpired($key)) {
                continue;
            }

            $data[$key] = $row['value'];
        }

        return $data;
    }

    /**
     * Extract all hash data
     * Reused from RdbPersistence
     */
    private function extractHashData(): array
    {
        $data = [];
        $keys = $this->hashStorage->getAllKeys();

        foreach ($keys as $key) {
            // Skip keys that have expired
            if ($this->keyExpiry->isExpired($key)) {
                continue;
            }

            $data[$key] = $this->hashStorage->hGetAll($key);
        }

        return $data;
    }

    /**
     * Extract all list data
     * Reused from RdbPersistence
     */
    private function extractListData(): array
    {
        $data = [];
        $keys = $this->listStorage->getAllKeys();

        foreach ($keys as $key) {
            // Skip keys that have expired
            if ($this->keyExpiry->isExpired($key)) {
                continue;
            }

            $meta = $this->listStorage->getMetadata($key);
            if ($meta && $meta['size'] > 0) {
                $data[$key] = $this->listStorage->lrange($key, 0, -1);
            }
        }

        return $data;
    }
}