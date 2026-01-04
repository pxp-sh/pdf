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
namespace PXP\PDF\Fpdf\Object\Array;

use PXP\PDF\Fpdf\Object\Base\PDFArray;
use PXP\PDF\Fpdf\Object\Base\PDFReference;

/**
 * Specialized array for /Kids entries (page references).
 */
final class KidsArray extends PDFArray
{
    /**
     * Add a page reference by object number.
     */
    public function addPage(int $pageObjectNumber, int $generation = 0): self
    {
        $this->add(new PDFReference($pageObjectNumber, $generation));

        return $this;
    }

    /**
     * Get page reference at index.
     */
    public function getPage(int $index): ?PDFReference
    {
        $item = $this->get($index);

        return $item instanceof PDFReference ? $item : null;
    }

    /**
     * Get all page object numbers.
     *
     * @return array<int>
     */
    public function getPageNumbers(): array
    {
        $numbers = [];

        foreach ($this->getAll() as $item) {
            if ($item instanceof PDFReference) {
                $numbers[] = $item->getObjectNumber();
            }
        }

        return $numbers;
    }
}
