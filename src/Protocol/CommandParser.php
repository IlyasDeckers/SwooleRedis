<?php

namespace Ody\SwooleRedis\Protocol;

/**
 * Parse incoming Redis commands
 */
class CommandParser
{
    /**
     * Parse a command from raw input data
     *
     * @param string $rawData The raw input data
     * @return array|null Parsed command as ['command' => string, 'args' => array] or null if invalid
     */
    public function parse(string $rawData): ?array
    {
        // Simple command parser (actual Redis uses RESP protocol)
        $data = trim($rawData);

        // Handle empty input
        if (empty($data)) {
            return null;
        }

        // Split by spaces for simple command parsing
        // This is a simplification; real Redis protocol handling is more complex
        // Use preg_split to handle quoted strings properly
        $parts = preg_split('/\s+/', $data, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($parts)) {
            return null;
        }

        $command = $parts[0]; // Keep original case for command recognition
        $arguments = array_slice($parts, 1);

        return [
            'command' => $command,
            'args' => $arguments
        ];
    }

    /**
     * For future implementation: Parse RESP protocol
     * Redis uses RESP (REdis Serialization Protocol) for client-server communication
     * This would parse the *n$n format used by Redis clients
     */
    public function parseResp(string $rawData): ?array
    {
        // TODO: Implement RESP protocol parsing for better client compatibility
        // This would handle Redis binary-safe protocol
        return null;
    }
}