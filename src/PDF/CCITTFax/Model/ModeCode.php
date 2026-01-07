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
namespace PXP\PDF\CCITTFax\Model;

class ModeCode
{
    public int $bitsUsed;
    public int $mask;
    public int $value;
    public Mode $type;

    public function __construct(int $bitsUsed, int $mask, int $value, Mode $type)
    {
        $this->bitsUsed = $bitsUsed;
        $this->mask     = $mask;
        $this->value    = $value;
        $this->type     = $type;
    }

    public function getVerticalOffset(): int
    {
        return match ($this->type) {
            Mode::VerticalZero => 0,
            Mode::VerticalL1   => -1,
            Mode::VerticalR1   => 1,
            Mode::VerticalL2   => -2,
            Mode::VerticalR2   => 2,
            Mode::VerticalL3   => -3,
            Mode::VerticalR3   => 3,
            default            => 0,
        };
    }

    public function matches(int $data): bool
    {
        return ($data & $this->mask) === $this->value;
    }

    public function getBitsUsed(): int
    {
        return $this->bitsUsed;
    }

    public function getType(): Mode
    {
        return $this->type;
    }
}
