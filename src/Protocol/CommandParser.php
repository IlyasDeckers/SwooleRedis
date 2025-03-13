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
        // Check if this is RESP protocol by looking for the array prefix (*)
        if (strlen($rawData) > 0 && $rawData[0] === '*') {
            return $this->parseResp($rawData);
        }

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
     * Parse Redis RESP protocol
     *
     * RESP (REdis Serialization Protocol) is used for client-server communication
     * It follows the format *n$n... (array of bulk strings)
     *
     * @param string $rawData The raw RESP data
     * @return array|null Parsed command as ['command' => string, 'args' => array] or null if invalid
     */
    public function parseResp(string $rawData): ?array
    {
        $respParser = new RespParser($rawData);
        $result = $respParser->parse();

        if (!$result || !is_array($result) || empty($result)) {
            return null;
        }

        // The first element should be the command, the rest are arguments
        $command = $result[0];
        $arguments = array_slice($result, 1);

        return [
            'command' => $command,
            'args' => $arguments
        ];
    }
}

/**
 * Helper class to parse RESP protocol
 */
class RespParser
{
    private string $data;
    private int $offset = 0;

    public function __construct(string $data)
    {
        $this->data = $data;
    }

    /**
     * Parse RESP data
     *
     * @return mixed Parsed data (string, integer, array, or null)
     */
    public function parse()
    {
        if ($this->offset >= strlen($this->data)) {
            return null;
        }

        $type = $this->data[$this->offset];
        $this->offset++;

        switch ($type) {
            case '+': // Simple String
                return $this->parseSimpleString();
            case '-': // Error
                return $this->parseError();
            case ':': // Integer
                return $this->parseInteger();
            case '$': // Bulk String
                return $this->parseBulkString();
            case '*': // Array
                return $this->parseArray();
            default:
                throw new \Exception("Unknown RESP type: " . $type);
        }
    }

    /**
     * Parse a simple string (type +)
     */
    private function parseSimpleString(): string
    {
        return $this->readLine();
    }

    /**
     * Parse an error (type -)
     */
    private function parseError(): string
    {
        return $this->readLine();
    }

    /**
     * Parse an integer (type :)
     */
    private function parseInteger(): int
    {
        return (int) $this->readLine();
    }

    /**
     * Parse a bulk string (type $)
     */
    private function parseBulkString(): ?string
    {
        $length = (int) $this->readLine();

        if ($length < 0) {
            return null; // $-1\r\n represents a null bulk string
        }

        $string = substr($this->data, $this->offset, $length);
        $this->offset += $length;

        // Skip the trailing \r\n
        $this->offset += 2;

        return $string;
    }

    /**
     * Parse an array (type *)
     */
    private function parseArray(): ?array
    {
        $count = (int) $this->readLine();

        if ($count < 0) {
            return null; // *-1\r\n represents a null array
        }

        $array = [];
        for ($i = 0; $i < $count; $i++) {
            $array[] = $this->parse();
        }

        return $array;
    }

    /**
     * Read a line until \r\n
     */
    private function readLine(): string
    {
        $end = strpos($this->data, "\r\n", $this->offset);

        if ($end === false) {
            throw new \Exception("Missing CRLF");
        }

        $line = substr($this->data, $this->offset, $end - $this->offset);
        $this->offset = $end + 2; // Skip the \r\n

        return $line;
    }
}