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

enum OutputDestination: string
{
    case INLINE = 'I';
    case DOWNLOAD = 'D';
    case FILE = 'F';
    case STRING = 'S';

    public static function fromString(string $dest): self
    {
        return match (strtoupper($dest)) {
            'I' => self::INLINE,
            'D' => self::DOWNLOAD,
            'F' => self::FILE,
            'S' => self::STRING,
            default => throw new \InvalidArgumentException('Incorrect output destination: ' . $dest),
        };
    }
}
