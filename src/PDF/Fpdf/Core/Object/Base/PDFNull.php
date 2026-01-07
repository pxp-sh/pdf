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
 * Represents a PDF null value.
 */
final class PDFNull extends PDFObject
{
    public function __toString(): string
    {
        return 'null';
    }
}
