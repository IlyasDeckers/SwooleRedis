<?php

namespace Ody\SwooleRedis\Command;

use Ody\SwooleRedis\Protocol\ResponseFormatter;
use Swoole\Server;

/**
 * Implements Redis pub/sub commands
 */
class PubSubCommands implements CommandInterface
{
    private $subscribers; // Remove type hint to avoid intersection type error
    private ResponseFormatter $formatter;
    private ?Server $server;

    public function __construct(
        &$subscribers,
        ResponseFormatter $formatter
    ) {
        $this->subscribers = &$subscribers;
        $this->formatter = $formatter;
        $this->server = null;
    }

    /**
     * Set the Swoole server instance
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

        // Use the first argument to determine the command
        $command = strtoupper(array_shift($args));

        switch ($command) {
            case 'SUBSCRIBE':
                return $this->subscribe($clientId, $args);

            case 'PUBLISH':
                return $this->publish($args);

            case 'UNSUBSCRIBE':
                return $this->unsubscribe($clientId, $args);

            case 'PUBSUB':
                return $this->pubsub($args);

            default:
                return $this->formatter->error("Unknown command '{$command}'");
        }
    }

    /**
     * Implement SUBSCRIBE command
     */
    private function subscribe(int $clientId, array $args): string
    {
        if (empty($args)) {
            return $this->formatter->error("Wrong number of arguments for SUBSCRIBE command");
        }

        $channels = $args;
        $responses = [];

        foreach ($channels as $channel) {
            if (!isset($this->subscribers[$channel])) {
                $this->subscribers[$channel] = [];
            }

            if (!in_array($clientId, $this->subscribers[$channel])) {
                $this->subscribers[$channel][] = $clientId;
            }

            $responses[] = $this->formatter->subscriptionMessage(
                'subscribe',
                $channel,
                count($this->subscribers[$channel])
            );
        }

        return implode('', $responses);
    }

    /**
     * Implement PUBLISH command
     */
    private function publish(array $args): string
    {
        if (count($args) !== 2) {
            return $this->formatter->error("Wrong number of arguments for PUBLISH command");
        }

        $channel = $args[0];
        $message = $args[1];

        if (!isset($this->subscribers[$channel]) || empty($this->subscribers[$channel])) {
            return $this->formatter->integer(0); // No subscribers
        }

        $count = 0;

        if ($this->server) {
            foreach ($this->subscribers[$channel] as $subscriber) {
                $this->server->send(
                    $subscriber,
                    $this->formatter->subscriptionMessage('message', $channel, $message)
                );
                $count++;
            }
        }

        return $this->formatter->integer($count);
    }

    /**
     * Implement UNSUBSCRIBE command
     */
    private function unsubscribe(int $clientId, array $args): string
    {
        // If no channels specified, unsubscribe from all
        if (empty($args)) {
            $channels = [];

            foreach ($this->subscribers as $channel => $subscribers) {
                if (in_array($clientId, $subscribers)) {
                    $channels[] = $channel;
                }
            }
        } else {
            $channels = $args;
        }

        $responses = [];

        foreach ($channels as $channel) {
            $count = 0;

            if (isset($this->subscribers[$channel])) {
                if (($key = array_search($clientId, $this->subscribers[$channel])) !== false) {
                    unset($this->subscribers[$channel][$key]);
                    // Reindex array
                    $this->subscribers[$channel] = array_values($this->subscribers[$channel]);
                    $count = count($this->subscribers[$channel]);

                    // Remove channel if no subscribers
                    if (empty($this->subscribers[$channel])) {
                        unset($this->subscribers[$channel]);
                    }
                }
            }

            $responses[] = $this->formatter->subscriptionMessage(
                'unsubscribe',
                $channel,
                $count
            );
        }

        return implode('', $responses);
    }

    /**
     * Implement PUBSUB command (statistics about the pub/sub system)
     */
    private function pubsub(array $args): string
    {
        if (empty($args)) {
            return $this->formatter->error("Wrong number of arguments for PUBSUB command");
        }

        $subcommand = strtoupper($args[0]);

        switch ($subcommand) {
            case 'CHANNELS':
                // List active channels (with at least one subscriber)
                $pattern = isset($args[1]) ? $args[1] : '*';
                $channels = $this->getMatchingChannels($pattern);
                return $this->formatter->array($channels);

            case 'NUMSUB':
                // Return the number of subscribers for specified channels
                $channels = array_slice($args, 1);
                $result = [];

                foreach ($channels as $channel) {
                    $result[] = $channel;
                    $result[] = isset($this->subscribers[$channel]) ? count($this->subscribers[$channel]) : 0;
                }

                return $this->formatter->array($result);

            case 'NUMPAT':
                // In a full implementation, this would return the number of pattern subscriptions
                // We're not implementing patterns here
                return $this->formatter->integer(0);

            default:
                return $this->formatter->error("Unknown PUBSUB subcommand: {$subcommand}");
        }
    }

    /**
     * Get channels matching a pattern
     */
    private function getMatchingChannels(string $pattern): array
    {
        // Simple implementation - only supports * wildcard as a full match
        if ($pattern === '*') {
            return array_keys($this->subscribers);
        }

        // For now, just return exact matches
        if (isset($this->subscribers[$pattern])) {
            return [$pattern];
        }

        return [];
    }
}