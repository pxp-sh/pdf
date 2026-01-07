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
namespace PXP\PDF\Fpdf\Rendering\Color;

use function sprintf;

final class ColorManager
{
    private string $drawColor = '0 G';
    private string $fillColor = '0 g';
    private string $textColor = '0 g';
    private bool $colorFlag   = false;

    public function setDrawColor(int $r, ?int $g = null, ?int $b = null): string
    {
        if (($r === 0 && $g === 0 && $b === 0) || $g === null) {
            $this->drawColor = sprintf('%.3F G', $r / 255);
        } else {
            $this->drawColor = sprintf('%.3F %.3F %.3F RG', $r / 255, $g / 255, $b / 255);
        }

        return $this->drawColor;
    }

    public function setFillColor(int $r, ?int $g = null, ?int $b = null): string
    {
        if (($r === 0 && $g === 0 && $b === 0) || $g === null) {
            $this->fillColor = sprintf('%.3F g', $r / 255);
        } else {
            $this->fillColor = sprintf('%.3F %.3F %.3F rg', $r / 255, $g / 255, $b / 255);
        }

        $this->colorFlag = ($this->fillColor !== $this->textColor);

        return $this->fillColor;
    }

    public function setTextColor(int $r, ?int $g = null, ?int $b = null): void
    {
        if (($r === 0 && $g === 0 && $b === 0) || $g === null) {
            $this->textColor = sprintf('%.3F g', $r / 255);
        } else {
            $this->textColor = sprintf('%.3F %.3F %.3F rg', $r / 255, $g / 255, $b / 255);
        }

        $this->colorFlag = ($this->fillColor !== $this->textColor);
    }

    public function getDrawColor(): string
    {
        return $this->drawColor;
    }

    public function getFillColor(): string
    {
        return $this->fillColor;
    }

    public function getTextColor(): string
    {
        return $this->textColor;
    }

    public function hasColorFlag(): bool
    {
        return $this->colorFlag;
    }

    public function reset(): void
    {
        $this->drawColor = '0 G';
        $this->fillColor = '0 g';
        $this->textColor = '0 g';
        $this->colorFlag = false;
    }
}
