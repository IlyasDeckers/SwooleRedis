<?php

namespace Ody\SwooleRedis\Command;

use Ody\SwooleRedis\Protocol\ResponseFormatter;

/**
 * Implements Redis transaction commands (MULTI/EXEC/DISCARD/WATCH)
 */
class TransactionCommands implements CommandInterface
{
    private ResponseFormatter $formatter;
    private array $clientTransactions;
    private CommandFactory $commandFactory;

    public function __construct(
        ResponseFormatter $formatter,
        array &$clientTransactions,
        CommandFactory $commandFactory
    ) {
        $this->formatter = $formatter;
        $this->clientTransactions = &$clientTransactions;
        $this->commandFactory = $commandFactory;
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
            case 'MULTI':
                return $this->multi($clientId);

            case 'EXEC':
                return $this->exec($clientId);

            case 'DISCARD':
                return $this->discard($clientId);

            case 'WATCH':
                return $this->watch($clientId, $args);

            case 'UNWATCH':
                return $this->unwatch($clientId);

            default:
                // If the client is in a transaction context, queue the command
                if (isset($this->clientTransactions[$clientId]) &&
                    $this->clientTransactions[$clientId]['state'] === 'MULTI') {

                    // Add the original command back to arguments
                    array_unshift($args, $command);

                    // Queue the command for later execution
                    $this->clientTransactions[$clientId]['commands'][] = $args;

                    return $this->formatter->simpleString("QUEUED");
                }

                return $this->formatter->error("Unknown command '{$command}'");
        }
    }

    /**
     * Implement MULTI command - start a new transaction
     */
    private function multi(int $clientId): string
    {
        // Check if client is already in a transaction
        if (isset($this->clientTransactions[$clientId]) &&
            $this->clientTransactions[$clientId]['state'] === 'MULTI') {
            return $this->formatter->error("MULTI calls can not be nested");
        }

        // Start a new transaction
        $this->clientTransactions[$clientId] = [
            'state' => 'MULTI',
            'commands' => [],
            'watched_keys' => []
        ];

        return $this->formatter->simpleString("OK");
    }

    /**
     * Implement EXEC command - execute a transaction
     */
    private function exec(int $clientId): string
    {
        // Check if client is in a transaction
        if (!isset($this->clientTransactions[$clientId]) ||
            $this->clientTransactions[$clientId]['state'] !== 'MULTI') {
            return $this->formatter->error("EXEC without MULTI");
        }

        // Get the transaction data
        $transaction = $this->clientTransactions[$clientId];

        // Check watched keys
        if (!empty($transaction['watched_keys']) && $this->hasWatchedKeysChanged($transaction['watched_keys'])) {
            // If watched keys have changed, abort the transaction
            unset($this->clientTransactions[$clientId]);
            return $this->formatter->nullResponse();
        }

        // Execute the queued commands
        $results = [];
        foreach ($transaction['commands'] as $commandArgs) {
            // Extract the command name
            $command = $commandArgs[0];

            // Create the appropriate command handler
            $handler = $this->commandFactory->create($command);

            // Execute the command
            $result = $handler->execute($clientId, $commandArgs);

            // Store the result
            $results[] = $result;
        }

        // Clear the transaction
        unset($this->clientTransactions[$clientId]);

        // Return the results as a multi-bulk response
        return $this->formatter->nestedArray($results);
    }

    /**
     * Implement DISCARD command - discard a transaction
     */
    private function discard(int $clientId): string
    {
        // Check if client is in a transaction
        if (!isset($this->clientTransactions[$clientId]) ||
            $this->clientTransactions[$clientId]['state'] !== 'MULTI') {
            return $this->formatter->error("DISCARD without MULTI");
        }

        // Clear the transaction
        unset($this->clientTransactions[$clientId]);

        return $this->formatter->simpleString("OK");
    }

    /**
     * Implement WATCH command - watch keys for changes
     */
    private function watch(int $clientId, array $args): string
    {
        if (empty($args)) {
            return $this->formatter->error("Wrong number of arguments for WATCH command");
        }

        // Check if client is already in a transaction
        if (isset($this->clientTransactions[$clientId]) &&
            $this->clientTransactions[$clientId]['state'] === 'MULTI') {
            return $this->formatter->error("WATCH inside MULTI is not allowed");
        }

        // Initialize transaction entry if it doesn't exist
        if (!isset($this->clientTransactions[$clientId])) {
            $this->clientTransactions[$clientId] = [
                'state' => 'WATCH',
                'commands' => [],
                'watched_keys' => []
            ];
        }

        // Add the keys to the watched list
        foreach ($args as $key) {
            if (!in_array($key, $this->clientTransactions[$clientId]['watched_keys'])) {
                $this->clientTransactions[$clientId]['watched_keys'][] = $key;
            }
        }

        return $this->formatter->simpleString("OK");
    }

    /**
     * Implement UNWATCH command - forget all watched keys
     */
    private function unwatch(int $clientId): string
    {
        // If client has a transaction entry, clear the watched keys
        if (isset($this->clientTransactions[$clientId])) {
            $this->clientTransactions[$clientId]['watched_keys'] = [];

            // If client was only in WATCH state (not MULTI), remove transaction entry
            if ($this->clientTransactions[$clientId]['state'] === 'WATCH') {
                unset($this->clientTransactions[$clientId]);
            }
        }

        return $this->formatter->simpleString("OK");
    }

    /**
     * Check if any of the watched keys have changed
     *
     * @param array $watchedKeys The keys being watched
     * @return bool True if any watched key has changed
     */
    private function hasWatchedKeysChanged(array $watchedKeys): bool
    {
        // In a real implementation, we would track modifications to keys
        // For this simplified version, we'll always return false
        // This assumes no concurrent modifications
        return false;
    }
}