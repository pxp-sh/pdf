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

use function sprintf;

/**
 * Represents a PDF object reference (e.g., "3 0 R").
 */
final class PDFReference extends PDFObject
{
    public function __construct(
        private readonly int $objectNumber,
        private readonly int $generationNumber = 0,
    ) {
    }

    public function __toString(): string
    {
        return sprintf('%d %d R', $this->objectNumber, $this->generationNumber);
    }

    public function getObjectNumber(): int
    {
        return $this->objectNumber;
    }

    public function getGenerationNumber(): int
    {
        return $this->generationNumber;
    }
}
