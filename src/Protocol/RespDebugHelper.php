<?php

namespace Ody\SwooleRedis\Protocol;

/**
 * Helper class for debugging RESP protocol
 */
class RespDebugHelper
{
    /**
     * Convert RESP binary data to a human-readable format
     *
     * @param string $data The RESP data
     * @return string Human-readable representation
     */
    public static function formatRespForDisplay(string $data): string
    {
        $output = '';
        $lines = explode("\r\n", $data);
        $indent = 0;

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }

            $type = substr($line, 0, 1);
            $value = substr($line, 1);

            switch ($type) {
                case '*': // Array
                    $count = (int)$value;
                    if ($count < 0) {
                        $output .= str_repeat('  ', $indent) . "*NULL ARRAY*\n";
                    } else {
                        $output .= str_repeat('  ', $indent) . "*ARRAY($count)*\n";
                        $indent++;
                    }
                    break;

                case '$': // Bulk String
                    $length = (int)$value;
                    if ($length < 0) {
                        $output .= str_repeat('  ', $indent) . "$NULL STRING$\n";
                    } else {
                        $output .= str_repeat('  ', $indent) . "$BULK_STRING($length)$\n";
                    }
                    break;

                case '+': // Simple String
                    $output .= str_repeat('  ', $indent) . "+\"$value\"+\n";
                    break;

                case '-': // Error
                    $output .= str_repeat('  ', $indent) . "-ERROR: \"$value\"-\n";
                    break;

                case ':': // Integer
                    $output .= str_repeat('  ', $indent) . ":$value:\n";
                    break;

                default: // Probably bulk string content
                    // Check if it's printable
                    if (self::isPrintable($line)) {
                        $output .= str_repeat('  ', $indent) . "\"$line\"\n";
                    } else {
                        $output .= str_repeat('  ', $indent) . "[BINARY DATA: " . self::formatBinary($line) . "]\n";
                    }
            }
        }

        return $output;
    }

    /**
     * Check if a string is printable
     */
    private static function isPrintable(string $str): bool
    {
        return ctype_print($str) || empty($str);
    }

    /**
     * Format binary data for display
     */
    private static function formatBinary(string $data): string
    {
        $hex = bin2hex($data);
        $formatted = '';

        for ($i = 0; $i < strlen($hex); $i += 2) {
            $formatted .= substr($hex, $i, 2);
            if ($i % 8 == 6) {
                $formatted .= ' ';
            }
        }

        return $formatted;
    }

    /**
     * Log RESP commands for debugging
     *
     * @param string $data The RESP data
     * @param string $direction 'in' for incoming, 'out' for outgoing
     * @param string $logFile Path to log file
     */
    public static function logRespCommand(string $data, string $direction, string $logFile = '/tmp/resp_debug.log'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $directionStr = strtoupper($direction) === 'IN' ? 'INCOMING' : 'OUTGOING';

        $logEntry = "[$timestamp] $directionStr RESP:\n";
        $logEntry .= self::formatRespForDisplay($data);
        $logEntry .= "RAW HEX: " . bin2hex($data) . "\n";
        $logEntry .= str_repeat('-', 50) . "\n";

        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}