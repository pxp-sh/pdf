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
namespace PXP\PDF\Fpdf\Object\Dictionary;

use function is_int;
use PXP\PDF\Fpdf\Object\Base\PDFArray;
use PXP\PDF\Fpdf\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Object\Base\PDFName;
use PXP\PDF\Fpdf\Object\Base\PDFReference;

/**
 * Specialized dictionary for /Resources.
 */
final class ResourcesDictionary extends PDFDictionary
{
    public function __construct()
    {
        parent::__construct();
        $this->setProcSet(['PDF', 'Text', 'ImageB', 'ImageC', 'ImageI']);
    }

    /**
     * Set ProcSet array.
     *
     * @param array<string> $procSet
     */
    public function setProcSet(array $procSet): self
    {
        $procSetArray = new PDFArray;

        foreach ($procSet as $proc) {
            $procSetArray->add(new PDFName($proc));
        }

        $this->addEntry('/ProcSet', $procSetArray);

        return $this;
    }

    /**
     * Add a font resource.
     */
    public function addFont(string $fontName, int|PDFReference $fontObjectNumber): self
    {
        $fonts = $this->getEntry('/Font');

        if (!$fonts instanceof PDFDictionary) {
            $fonts = new PDFDictionary;
            $this->addEntry('/Font', $fonts);
        }

        if (is_int($fontObjectNumber)) {
            $fontObjectNumber = new PDFReference($fontObjectNumber);
        }

        $fonts->addEntry($fontName, $fontObjectNumber);

        return $this;
    }

    /**
     * Add an XObject resource (image).
     */
    public function addXObject(string $xObjectName, int|PDFReference $xObjectNumber): self
    {
        $xObjects = $this->getEntry('/XObject');

        if (!$xObjects instanceof PDFDictionary) {
            $xObjects = new PDFDictionary;
            $this->addEntry('/XObject', $xObjects);
        }

        if (is_int($xObjectNumber)) {
            $xObjectNumber = new PDFReference($xObjectNumber);
        }

        $xObjects->addEntry($xObjectName, $xObjectNumber);

        return $this;
    }

    /**
     * Get Font dictionary.
     */
    public function getFonts(): ?PDFDictionary
    {
        $fonts = $this->getEntry('/Font');

        return $fonts instanceof PDFDictionary ? $fonts : null;
    }

    /**
     * Get XObject dictionary.
     */
    public function getXObjects(): ?PDFDictionary
    {
        $xObjects = $this->getEntry('/XObject');

        return $xObjects instanceof PDFDictionary ? $xObjects : null;
    }
}
