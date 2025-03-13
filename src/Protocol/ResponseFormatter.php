<?php

namespace Ody\SwooleRedis\Protocol;

/**
 * Format responses according to the Redis RESP protocol
 */
class ResponseFormatter
{
    /**
     * Format a simple string response
     *
     * @param string $str The string to format
     * @return string Formatted response
     */
    public function simpleString(string $str): string
    {
        // Ensure no CRLF in simple strings (as per RESP protocol)
        $str = str_replace(["\r", "\n"], [' ', ' '], $str);
        return "+{$str}\r\n";
    }

    /**
     * Format an error response
     *
     * @param string $message The error message
     * @param string $prefix Error prefix (default: ERR)
     * @return string Formatted response
     */
    public function error(string $message, string $prefix = 'ERR'): string
    {
        // Ensure no CRLF in error messages
        $message = str_replace(["\r", "\n"], [' ', ' '], $message);
        return "-{$prefix} {$message}\r\n";
    }

    /**
     * Format an integer response
     *
     * @param int $number The integer to format
     * @return string Formatted response
     */
    public function integer(int $number): string
    {
        return ":{$number}\r\n";
    }

    /**
     * Format a bulk string response
     *
     * @param string|null $str The string to format, or null for nil response
     * @return string Formatted response
     */
    public function bulkString(?string $str): string
    {
        if ($str === null) {
            return "$-1\r\n"; // Nil bulk string
        }

        return "$" . strlen($str) . "\r\n" . $str . "\r\n";
    }

    /**
     * Format an array response
     *
     * @param array $arr The array to format
     * @return string Formatted response
     */
    public function array(array $arr): string
    {
        if (empty($arr)) {
            return "*0\r\n";
        }

        $response = "*" . count($arr) . "\r\n";

        foreach ($arr as $item) {
            if (is_integer($item)) {
                $response .= $this->integer($item);
            } elseif (is_string($item)) {
                $response .= $this->bulkString($item);
            } elseif (is_array($item)) {
                $response .= $this->array($item);
            } elseif ($item === null) {
                $response .= "$-1\r\n";
            } elseif (is_bool($item)) {
                // Handle boolean values (convert to integers as per Redis convention)
                $response .= $this->integer($item ? 1 : 0);
            } elseif (is_float($item)) {
                // Handle float values (convert to strings as per Redis convention)
                $response .= $this->bulkString((string)$item);
            } else {
                // Convert other types to string
                $response .= $this->bulkString((string)$item);
            }
        }

        return $response;
    }

    /**
     * Format a nested array response (multi-bulk)
     * Used for multi-key responses like EXEC, SCAN, etc.
     *
     * @param array $arrays Array of arrays
     * @return string Formatted response
     */
    public function nestedArray(array $arrays): string
    {
        return $this->array($arrays);
    }

    /**
     * Format a nil response (null array)
     *
     * @return string Formatted response
     */
    public function nilArray(): string
    {
        return "*-1\r\n";
    }

    /**
     * Format a subscription message
     *
     * @param string $type The message type (subscribe, message, etc.)
     * @param string $channel The channel name
     * @param string|int $payload The message payload or subscription count
     * @return string Formatted response
     */
    public function subscriptionMessage(string $type, string $channel, $payload): string
    {
        $response = "*3\r\n";
        $response .= "$" . strlen($type) . "\r\n" . $type . "\r\n";
        $response .= "$" . strlen($channel) . "\r\n" . $channel . "\r\n";

        if (is_integer($payload)) {
            $response .= ":{$payload}\r\n";
        } else {
            $response .= "$" . strlen($payload) . "\r\n" . $payload . "\r\n";
        }

        return $response;
    }

    /**
     * Format a binary-safe bulk string response
     * This is useful for handling binary data like images, etc.
     *
     * @param string|null $data The binary data to format, or null for nil response
     * @return string Formatted response
     */
    public function binaryBulkString(?string $data): string
    {
        return $this->bulkString($data);
    }

    /**
     * Format a null response
     *
     * @return string Formatted response
     */
    public function nullResponse(): string
    {
        return "$-1\r\n";
    }
}