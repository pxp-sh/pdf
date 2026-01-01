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
use PXP\PDF\Fpdf\Object\Base\PDFNumber;

/**
 * Specialized array for /MediaBox (4 float values: llx, lly, urx, ury).
 */
final class MediaBoxArray extends PDFArray
{
    /**
     * Create MediaBox from array of 4 values.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $values
     */
    public function __construct(array $values = [0.0, 0.0, 612.0, 792.0])
    {
        parent::__construct();
        $this->setValues($values);
    }

    /**
     * Set MediaBox values.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $values
     */
    public function setValues(array $values): self
    {
        $this->items = [];
        foreach ($values as $value) {
            $this->add(new PDFNumber($value));
        }

        return $this;
    }

    /**
     * Get MediaBox values as array.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function getValues(): array
    {
        $values = [];
        foreach ($this->getAll() as $item) {
            if ($item instanceof PDFNumber) {
                $values[] = (float) $item->getValue();
            }
        }

        // Ensure 4 values
        while (count($values) < 4) {
            $values[] = 0.0;
        }

        return [
            $values[0] ?? 0.0,
            $values[1] ?? 0.0,
            $values[2] ?? 612.0,
            $values[3] ?? 792.0,
        ];
    }

    /**
     * Get lower-left X.
     */
    public function getLLX(): float
    {
        $values = $this->getValues();

        return $values[0];
    }

    /**
     * Get lower-left Y.
     */
    public function getLLY(): float
    {
        $values = $this->getValues();

        return $values[1];
    }

    /**
     * Get upper-right X.
     */
    public function getURX(): float
    {
        $values = $this->getValues();

        return $values[2];
    }

    /**
     * Get upper-right Y.
     */
    public function getURY(): float
    {
        $values = $this->getValues();

        return $values[3];
    }

    /**
     * Get width.
     */
    public function getWidth(): float
    {
        return $this->getURX() - $this->getLLX();
    }

    /**
     * Get height.
     */
    public function getHeight(): float
    {
        return $this->getURY() - $this->getLLY();
    }
}
