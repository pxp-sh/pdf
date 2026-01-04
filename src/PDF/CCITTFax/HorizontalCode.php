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

class HorizontalCode
{
    public int $bitsUsed;
    public int $mask;
    public int $value;
    public int $color;
    public int $pixels;
    public bool $terminating;

    public function __construct(
        int $bitsUsed,
        int $mask,
        int $value,
        int $color,
        int $pixels,
        bool $terminating = false
    ) {
        $this->bitsUsed    = $bitsUsed;
        $this->mask        = $mask;
        $this->value       = $value;
        $this->color       = $color;
        $this->pixels      = $pixels;
        $this->terminating = $terminating;
    }

    public function matches(int $data): bool
    {
        return ($data & $this->mask) === $this->value;
    }

    public function getBitsUsed(): int
    {
        return $this->bitsUsed;
    }

    public function getRunLength(): int
    {
        return $this->pixels;
    }

    public function isTerminating(): bool
    {
        return $this->terminating;
    }

    public function isMakeup(): bool
    {
        return !$this->terminating;
    }
}
