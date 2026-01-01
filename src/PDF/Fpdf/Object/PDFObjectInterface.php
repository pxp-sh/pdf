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

namespace PXP\PDF\Fpdf\Object;

/**
 * Base interface for all PDF objects.
 * All PDF objects must be stringable to convert to PDF format.
 */
interface PDFObjectInterface extends \Stringable
{
    /**
     * Convert the object to PDF string format.
     */
    public function __toString(): string;
}
