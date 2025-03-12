<?php

namespace Ody\SwooleRedis\Command;

use Ody\SwooleRedis\Protocol\ResponseFormatter;

/**
 * A fallback command handler for unknown commands
 */
class UnknownCommand implements CommandInterface
{
    private ResponseFormatter $responseFormatter;
    private string $commandName;

    public function __construct(ResponseFormatter $responseFormatter, string $commandName)
    {
        $this->responseFormatter = $responseFormatter;
        $this->commandName = $commandName;
    }

    public function execute(int $clientId, array $args): string
    {
        return $this->responseFormatter->error("Unknown command '{$this->commandName}'");
    }
}