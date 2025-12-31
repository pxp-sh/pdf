<?php

declare(strict_types=1);

/**
 * Copyright (c) 2025 PXP
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/pxp-sh/pdf
 *
 */

namespace PXP\PDF\Fpdf\ValueObject;

final readonly class PageSize
{
    private const STANDARD_SIZES = [
        'a3' => [841.89, 1190.55],
        'a4' => [595.28, 841.89],
        'a5' => [420.94, 595.28],
        'letter' => [612, 792],
        'legal' => [612, 1008],
    ];

    public function __construct(
        private float $width,
        private float $height,
    ) {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('Page dimensions must be positive');
        }
    }

    public static function fromString(string $size, float $scaleFactor): self
    {
        $size = strtolower($size);
        if (!isset(self::STANDARD_SIZES[$size])) {
            throw new \InvalidArgumentException('Unknown page size: ' . $size);
        }

        [$widthPt, $heightPt] = self::STANDARD_SIZES[$size];
        return new self($widthPt / $scaleFactor, $heightPt / $scaleFactor);
    }

    public static function fromArray(array $size, float $scaleFactor): self
    {
        [$widthPt, $heightPt] = $size;
        if ($widthPt > $heightPt) {
            return new self($heightPt / $scaleFactor, $widthPt / $scaleFactor);
        }

        return new self($widthPt / $scaleFactor, $heightPt / $scaleFactor);
    }

    public function getWidth(): float
    {
        return $this->width;
    }

    public function getHeight(): float
    {
        return $this->height;
    }

    public function getWidthInPoints(float $scaleFactor): float
    {
        return $this->width * $scaleFactor;
    }

    public function getHeightInPoints(float $scaleFactor): float
    {
        return $this->height * $scaleFactor;
    }

    public function equals(self $other): bool
    {
        return $this->width === $other->width && $this->height === $other->height;
    }
}
