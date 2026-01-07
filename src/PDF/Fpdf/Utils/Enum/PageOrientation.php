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
namespace PXP\PDF\Fpdf\Utils\Enum;

use function strtolower;
use InvalidArgumentException;

enum PageOrientation: string
{
    public static function fromString(string $orientation): self
    {
        $orientation = strtolower($orientation);

        return match ($orientation) {
            'p', 'portrait' => self::PORTRAIT,
            'l', 'landscape' => self::LANDSCAPE,
            default => throw new InvalidArgumentException('Incorrect orientation: ' . $orientation),
        };
    }
    case PORTRAIT  = 'P';
    case LANDSCAPE = 'L';
}
