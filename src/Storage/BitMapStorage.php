<?php

namespace Ody\SwooleRedis\Storage;

use Ody\SwooleRedis\MemoryManager;

/**
 * Storage for bitmap operations
 *
 * Note: This implementation uses the string storage with bit operations
 */
class BitMapStorage
{
    private StringStorage $stringStorage;

    public function __construct(StringStorage $stringStorage)
    {
        $this->stringStorage = $stringStorage;
    }

    /**
     * Get the value of a bit at offset
     *
     * @param string $key The key
     * @param int $offset The bit offset
     * @return int 0 or 1 (the bit value)
     */
    public function getBit(string $key, int $offset): int
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException("Bit offset is out of range");
        }

        $value = $this->stringStorage->get($key);

        // If key doesn't exist, all bits are 0
        if ($value === null) {
            return 0;
        }

        $byteOffset = (int)($offset / 8);
        $bitOffset = $offset % 8;

        // If the byte offset is beyond our string length, the bit is 0
        if ($byteOffset >= strlen($value)) {
            return 0;
        }

        // Get the byte at the specified offset
        $byte = ord($value[$byteOffset]);

        // Check if the bit is set (1) or not (0)
        return ($byte & (1 << (7 - $bitOffset))) ? 1 : 0;
    }

    /**
     * Set the value of a bit at offset
     *
     * @param string $key The key
     * @param int $offset The bit offset
     * @param int $value The bit value (0 or 1)
     * @return int The original bit value
     */
    public function setBit(string $key, int $offset, int $value): int
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException("Bit offset is out of range");
        }

        if ($value !== 0 && $value !== 1) {
            throw new \InvalidArgumentException("Bit value must be 0 or 1");
        }

        $byteOffset = (int)($offset / 8);
        $bitOffset = $offset % 8;

        $existingValue = $this->stringStorage->get($key);

        // If key doesn't exist, create a new string with zeros
        if ($existingValue === null) {
            $existingValue = '';
        }

        // Expand the string if needed
        $neededLength = $byteOffset + 1;
        if (strlen($existingValue) < $neededLength) {
            $existingValue .= str_repeat("\0", $neededLength - strlen($existingValue));
        }

        // Get the byte we need to modify
        $byte = ord($existingValue[$byteOffset]);

        // Get the original bit value
        $originalBit = ($byte & (1 << (7 - $bitOffset))) ? 1 : 0;

        // Set or clear the bit
        if ($value === 1) {
            // Set the bit
            $byte |= (1 << (7 - $bitOffset));
        } else {
            // Clear the bit
            $byte &= ~(1 << (7 - $bitOffset));
        }

        // Update the byte in our string
        $existingValue[$byteOffset] = chr($byte);

        // Save the updated string
        $this->stringStorage->set($key, $existingValue);

        return $originalBit;
    }

    /**
     * Count the number of set bits (population counting)
     *
     * @param string $key The key
     * @param int $start The start offset
     * @param int $end The end offset
     * @return int The number of set bits
     */
    public function bitCount(string $key, int $start = 0, int $end = -1): int
    {
        $value = $this->stringStorage->get($key);

        if ($value === null) {
            return 0;
        }

        // Adjust end if -1 (meaning the last byte)
        if ($end === -1) {
            $end = strlen($value) - 1;
        }

        // Make sure $start and $end are within bounds
        $start = max(0, $start);
        $end = min(strlen($value) - 1, $end);

        // If range is invalid, return 0
        if ($start > $end) {
            return 0;
        }

        // Extract the substring to count
        $substring = substr($value, $start, $end - $start + 1);

        // Count bits (using a lookup table for efficiency)
        $count = 0;
        for ($i = 0; $i < strlen($substring); $i++) {
            $count += $this->popCount(ord($substring[$i]));
        }

        return $count;
    }

    /**
     * Perform a bitwise operation between strings
     *
     * @param string $operation The operation (AND, OR, XOR, NOT)
     * @param string $destKey The destination key
     * @param array $sourceKeys The source keys
     * @return int The length of the string stored at the destination key
     */
    public function bitOp(string $operation, string $destKey, array $sourceKeys): int
    {
        $operation = strtoupper($operation);

        if (empty($sourceKeys)) {
            throw new \InvalidArgumentException("BITOP requires at least one source key");
        }

        // For NOT operation, only one source key is allowed
        if ($operation === 'NOT' && count($sourceKeys) !== 1) {
            throw new \InvalidArgumentException("BITOP NOT requires exactly one source key");
        }

        // Get values for all source keys
        $values = [];
        $maxLength = 0;
        foreach ($sourceKeys as $key) {
            $val = $this->stringStorage->get($key);
            if ($val === null) {
                $val = '';
            }
            $values[] = $val;
            $maxLength = max($maxLength, strlen($val));
        }

        // If there are no source keys or all are empty, the result is an empty string
        if ($maxLength === 0) {
            $this->stringStorage->set($destKey, '');
            return 0;
        }

        // Perform the operation
        $result = '';
        for ($i = 0; $i < $maxLength; $i++) {
            $byte = $this->getByteAt($values[0], $i);

            if ($operation === 'NOT') {
                $byte = ~$byte;
            } else {
                for ($j = 1; $j < count($values); $j++) {
                    $sourceByte = $this->getByteAt($values[$j], $i);

                    switch ($operation) {
                        case 'AND':
                            $byte &= $sourceByte;
                            break;
                        case 'OR':
                            $byte |= $sourceByte;
                            break;
                        case 'XOR':
                            $byte ^= $sourceByte;
                            break;
                        default:
                            throw new \InvalidArgumentException("Invalid BITOP operation: $operation");
                    }
                }
            }

            $result .= chr($byte);
        }

        // Store the result
        $this->stringStorage->set($destKey, $result);

        return strlen($result);
    }

    /**
     * Return the position of the first bit set to 1 or 0
     *
     * @param string $key The key
     * @param int $bit The bit value (0 or 1)
     * @param int $start The start offset
     * @param int $end The end offset
     * @return int The position of the first bit, or -1 if not found
     */
    public function bitPos(string $key, int $bit, int $start = 0, int $end = -1): int
    {
        if ($bit !== 0 && $bit !== 1) {
            throw new \InvalidArgumentException("Bit value must be 0 or 1");
        }

        $value = $this->stringStorage->get($key);

        if ($value === null) {
            // If key doesn't exist, all bits are 0
            return $bit === 0 ? 0 : -1;
        }

        // Adjust end if -1 (meaning the last byte)
        if ($end === -1) {
            $end = strlen($value) - 1;
        }

        // Make sure $start and $end are within bounds
        $start = max(0, $start);
        $end = min(strlen($value) - 1, $end);

        // If range is invalid, return -1
        if ($start > $end) {
            return -1;
        }

        // Search for the first bit
        for ($byteOffset = $start; $byteOffset <= $end; $byteOffset++) {
            $byte = ord($value[$byteOffset]);

            if (($bit === 1 && $byte !== 0) || ($bit === 0 && $byte !== 255)) {
                // Found a byte with at least one bit of the desired value
                for ($bitOffset = 0; $bitOffset < 8; $bitOffset++) {
                    $bitValue = ($byte & (1 << (7 - $bitOffset))) ? 1 : 0;

                    if ($bitValue === $bit) {
                        return ($byteOffset * 8) + $bitOffset;
                    }
                }
            }
        }

        return -1;
    }

    /**
     * Get a byte from a string at a specific offset, or 0 if beyond the string length
     *
     * @param string $str The string
     * @param int $offset The byte offset
     * @return int The byte value (0-255)
     */
    private function getByteAt(string $str, int $offset): int
    {
        if ($offset < strlen($str)) {
            return ord($str[$offset]);
        }
        return 0;
    }

    /**
     * Count the number of set bits in a byte (population count)
     *
     * @param int $byte The byte value (0-255)
     * @return int The number of set bits
     */
    private function popCount(int $byte): int
    {
        // Efficient bit counting using a small lookup table
        static $lookup = null;

        if ($lookup === null) {
            $lookup = array_fill(0, 256, 0);
            for ($i = 0; $i < 256; $i++) {
                $lookup[$i] = ($i & 1) + $lookup[$i >> 1];
            }
        }

        return $lookup[$byte];
    }
}