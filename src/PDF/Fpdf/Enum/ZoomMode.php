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

use function is_float;
use function strtolower;
use InvalidArgumentException;

enum ZoomMode: string
{
    public static function fromValue(float|string $value): float|string
    {
        if (is_float($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'fullpage', 'fullwidth', 'real', 'default' => strtolower($value),
            default => throw new InvalidArgumentException('Incorrect zoom display mode: ' . $value),
        };
    }
    case FULLPAGE  = 'fullpage';
    case FULLWIDTH = 'fullwidth';
    case REAL      = 'real';
    case DEFAULT   = 'default';
}
