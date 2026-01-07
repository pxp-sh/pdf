<?php

declare(strict_types=1);

/**
 * Copyright (c) 2025-2026 PXP
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/pxp-sh/pdf
 *
 */
namespace PXP\PDF\CCITTFax\Util;

use function feof;
use function fread;
use function is_resource;
use function is_string;
use function ord;
use function strlen;
use InvalidArgumentException;

class BitBuffer
{
    private int $buffer;
    private int $emptyBits;

    /** @var false|string String data or false if invalid */
    private false|string $source = '';
    private int $sourcePos;
    private int $bitsRead = 0;

    /** @var null|resource Stream resource for streaming input */
    private $stream;

    /** @var string Buffer for streaming input */
    private string $streamBuffer = '';

    /** @var int Position in stream buffer */
    private int $streamBufferPos = 0;

    /** @var bool Whether we've reached end of stream */
    private bool $streamEnded = false;

    /**
     * @param resource|string $source String data or stream resource
     */
    public function __construct($source)
    {
        if (!is_string($source) && !is_resource($source)) {
            throw new InvalidArgumentException('Source must be a string or a resource');
        }

        $this->buffer    = 0;
        $this->emptyBits = 32;

        if (is_resource($source)) {
            $this->stream    = $source;
            $this->source    = '';
            $this->sourcePos = 0;
        } else {
            $this->source    = $source;
            $this->sourcePos = 0;
        }

        $this->tryFillBuffer();
    }

    public function flushBits(int $count): void
    {
        $this->buffer = ($this->buffer << $count) & 0xFFFFFFFF;
        $this->emptyBits += $count;
        $this->bitsRead  += $count;
        $this->tryFillBuffer();
    }

    /**
     * @return array<int, int>
     */
    public function peak8(): array
    {
        return [
            ($this->buffer >> 24) & 0xFF,
            32 - $this->emptyBits,
        ];
    }

    /**
     * @return array<int, int>
     */
    public function peak16(): array
    {
        return [
            ($this->buffer >> 16) & 0xFFFF,
            32 - $this->emptyBits,
        ];
    }

    /**
     * @return array<int, int>
     */
    public function peak32(): array
    {
        return [
            $this->buffer,
            32 - $this->emptyBits,
        ];
    }

    public function hasData(): bool
    {
        if ($this->stream !== null) {
            // Streaming mode: check if buffer has data or stream has more
            if ($this->emptyBits < 32) {
                return true;
            }

            return !$this->streamEnded || $this->streamBufferPos < strlen($this->streamBuffer);
        }

        // String mode
        return !($this->emptyBits === 32 && $this->sourcePos >= strlen($this->source));
    }

    public function clear(): void
    {
        $this->buffer    = 0;
        $this->emptyBits = 32;
        $this->sourcePos = 0;
    }

    public function getBuffer(): int
    {
        return $this->buffer;
    }

    public function getEmptyBits(): int
    {
        return $this->emptyBits;
    }

    public function getBitsRead(): int
    {
        return $this->bitsRead;
    }

    /**
     * Get the number of bits available in the buffer.
     * This is the number of valid bits (32 - emptyBits).
     */
    public function available(): int
    {
        return 32 - $this->emptyBits;
    }

    /**
     * Get bits from the buffer without consuming them (peek + getBits combined).
     */
    public function getBits(int $count): int
    {
        [$value] = match ($count) {
            8       => $this->peak8(),
            16      => $this->peak16(),
            32      => $this->peak32(),
            default => throw new InvalidArgumentException("Unsupported bit count: {$count}"),
        };
        $this->flushBits($count);

        return $value;
    }

    private function tryFillBuffer(): void
    {
        while ($this->emptyBits > 7) {
            if ($this->stream !== null) {
                // Streaming mode
                if (!$this->fillFromStream()) {
                    break;
                }
            } else {
                // String mode
                if ($this->sourcePos >= strlen($this->source)) {
                    break;
                }
                $this->addByte(ord($this->source[$this->sourcePos]));
                $this->sourcePos++;
            }
        }
    }

    /**
     * Fill buffer from stream (streaming mode).
     *
     * @return bool True if byte was read, false if no more data
     */
    private function fillFromStream(): bool
    {
        // Try to read from stream buffer first
        if ($this->streamBufferPos < strlen($this->streamBuffer)) {
            $this->addByte(ord($this->streamBuffer[$this->streamBufferPos]));
            $this->streamBufferPos++;

            return true;
        }

        // Need to read more from stream
        if ($this->streamEnded) {
            return false;
        }

        // Read chunk from stream (4KB at a time for efficiency)
        $chunk = fread($this->stream, 4096);

        if ($chunk === false || $chunk === '') {
            $this->streamEnded = true;

            return false;
        }

        if (feof($this->stream)) {
            $this->streamEnded = true;
        }

        // Reset buffer with new chunk
        $this->streamBuffer    = $chunk;
        $this->streamBufferPos = 0;

        // Read first byte from new chunk
        if ($this->streamBuffer !== '') {
            $this->addByte(ord($this->streamBuffer[$this->streamBufferPos]));
            $this->streamBufferPos++;

            return true;
        }

        return false;
    }

    private function addByte(int $sourceByte): void
    {
        $padRight     = $this->emptyBits - 8;
        $zeroed       = (($this->buffer >> (8 + $padRight)) << (8 + $padRight)) & 0xFFFFFFFF;
        $this->buffer = ($zeroed | ($sourceByte << $padRight)) & 0xFFFFFFFF;
        $this->emptyBits -= 8;
    }
}
