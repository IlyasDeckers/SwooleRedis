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
        return "+{$str}\r\n";
    }

    /**
     * Format an error response
     *
     * @param string $message The error message
     * @return string Formatted response
     */
    public function error(string $message): string
    {
        return "-ERR {$message}\r\n";
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
            }
        }

        return $response;
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
}