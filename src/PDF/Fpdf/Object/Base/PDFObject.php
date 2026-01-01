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

use PXP\PDF\Fpdf\Object\PDFObjectInterface;

/**
 * Abstract base class for all PDF objects.
 */
abstract class PDFObject implements PDFObjectInterface
{
    /**
     * Convert the object to PDF string format.
     * Must be implemented by subclasses.
     */
    abstract public function __toString(): string;
}
