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

namespace PXP\PDF\Fpdf\Enum;

enum Unit: string
{
    case POINT = 'pt';
    case MILLIMETER = 'mm';
    case CENTIMETER = 'cm';
    case INCH = 'in';

    public static function fromString(string $unit): self
    {
        return match (strtolower($unit)) {
            'pt', 'point' => self::POINT,
            'mm', 'millimeter' => self::MILLIMETER,
            'cm', 'centimeter' => self::CENTIMETER,
            'in', 'inch' => self::INCH,
            default => throw new \InvalidArgumentException('Incorrect unit: ' . $unit),
        };
    }

    public function getScaleFactor(): float
    {
        return match ($this) {
            self::POINT => 1.0,
            self::MILLIMETER => 72.0 / 25.4,
            self::CENTIMETER => 72.0 / 2.54,
            self::INCH => 72.0,
        };
    }
}
