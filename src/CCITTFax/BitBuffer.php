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

class BitBuffer
{
    private int $buffer;
    private int $emptyBits;
    private string $source;
    private int $sourcePos;

    public function __construct(string $source)
    {
        $this->buffer = 0;
        $this->emptyBits = 32;
        $this->source = $source;
        $this->sourcePos = 0;
        $this->tryFillBuffer();
    }

    public function flushBits(int $count): void
    {
        $this->buffer = ($this->buffer << $count) & 0xFFFFFFFF;
        $this->emptyBits += $count;
        $this->tryFillBuffer();
    }

    public function peak8(): array
    {
        return [
            ($this->buffer >> 24) & 0xFF,
            32 - $this->emptyBits
        ];
    }

    public function peak16(): array
    {
        return [
            ($this->buffer >> 16) & 0xFFFF,
            32 - $this->emptyBits
        ];
    }

    public function peak32(): array
    {
        return [
            $this->buffer,
            32 - $this->emptyBits
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
        $this->buffer = 0;
        $this->emptyBits = 32;
        $this->sourcePos = 0;
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
        $padRight = $this->emptyBits - 8;
        $zeroed = (($this->buffer >> (8 + $padRight)) << (8 + $padRight)) & 0xFFFFFFFF;
        $this->buffer = ($zeroed | ($sourceByte << $padRight)) & 0xFFFFFFFF;
        $this->emptyBits -= 8;
    }

    public function getBuffer(): int
    {
        return $this->buffer;
    }
}
