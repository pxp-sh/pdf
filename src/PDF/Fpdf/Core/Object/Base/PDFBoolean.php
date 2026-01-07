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
namespace PXP\PDF\Fpdf\Core\Object\Base;

/**
 * Represents a PDF boolean value.
 */
final class PDFBoolean extends PDFObject
{
    public function __construct(
        private readonly bool $value,
    ) {
    }

    public function __toString(): string
    {
        return $this->value ? 'true' : 'false';
    }

    public function getValue(): bool
    {
        return $this->value;
    }
}
