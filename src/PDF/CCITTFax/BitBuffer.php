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
namespace PXP\PDF\CCITTFax;

use function ord;
use function strlen;
use InvalidArgumentException;

class BitBuffer
{
    private int $buffer;
    private int $emptyBits;
    private string $source;
    private int $sourcePos;
    private int $bitsRead = 0;

    public function __construct(string $source)
    {
        $this->buffer    = 0;
        $this->emptyBits = 32;
        $this->source    = $source;
        $this->sourcePos = 0;
        $this->tryFillBuffer();
    }

    public function flushBits(int $count): void
    {
        $this->buffer = ($this->buffer << $count) & 0xFFFFFFFF;
        $this->emptyBits += $count;
        $this->bitsRead  += $count;
        $this->tryFillBuffer();
    }

    public function peak8(): array
    {
        return [
            ($this->buffer >> 24) & 0xFF,
            32 - $this->emptyBits,
        ];
    }

    public function peak16(): array
    {
        return [
            ($this->buffer >> 16) & 0xFFFF,
            32 - $this->emptyBits,
        ];
    }

    public function peak32(): array
    {
        return [
            $this->buffer,
            32 - $this->emptyBits,
        ];
    }

    public function hasData(): bool
    {
        if ($this->emptyBits === 32 && $this->sourcePos >= strlen($this->source)) {
            return false;
        }

        return true;
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
            if ($this->sourcePos >= strlen($this->source)) {
                break;
            }
            $this->addByte(ord($this->source[$this->sourcePos]));
            $this->sourcePos++;
        }
    }

    private function addByte(int $sourceByte): void
    {
        $padRight     = $this->emptyBits - 8;
        $zeroed       = (($this->buffer >> (8 + $padRight)) << (8 + $padRight)) & 0xFFFFFFFF;
        $this->buffer = ($zeroed | ($sourceByte << $padRight)) & 0xFFFFFFFF;
        $this->emptyBits -= 8;
    }
}
