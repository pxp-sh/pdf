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

namespace PXP\PDF\Fpdf\Enum;

enum LayoutMode: string
{
    case SINGLE = 'single';
    case CONTINUOUS = 'continuous';
    case TWO = 'two';
    case DEFAULT = 'default';

    public static function fromString(string $layout): self
    {
        return match (strtolower($layout)) {
            'single' => self::SINGLE,
            'continuous' => self::CONTINUOUS,
            'two' => self::TWO,
            'default' => self::DEFAULT,
            default => throw new \InvalidArgumentException('Incorrect layout display mode: ' . $layout),
        };
    }
}
