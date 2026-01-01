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

namespace PXP\PDF\Fpdf\Object\Base;

/**
 * Represents a PDF number (integer or float).
 */
final class PDFNumber extends PDFObject
{
    public function __construct(
        private readonly int|float $value,
    ) {
    }

    public function getValue(): int|float
    {
        return $this->value;
    }

    public function __toString(): string
    {
        if (is_int($this->value)) {
            return (string) $this->value;
        }

        // Format float with up to 5 decimal places, remove trailing zeros
        $formatted = rtrim(sprintf('%.5F', $this->value), '0');
        $formatted = rtrim($formatted, '.');

        return $formatted;
    }
}
