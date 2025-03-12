<?php

namespace Ody\SwooleRedis\Command;

interface CommandInterface
{
    /**
     * Execute a Redis command
     *
     * @param int $clientId The client connection ID
     * @param array $args Command arguments
     * @return string The response to send back to the client
     */
    public function execute(int $clientId, array $args): string;
}