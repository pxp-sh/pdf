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
namespace PXP\PDF\Fpdf\Core\Object\Dictionary;

use function is_array;
use function is_int;
use PXP\PDF\Fpdf\Core\Object\Array\MediaBoxArray;
use PXP\PDF\Fpdf\Core\Object\Base\PDFArray;
use PXP\PDF\Fpdf\Core\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Core\Object\Base\PDFName;
use PXP\PDF\Fpdf\Core\Object\Base\PDFNumber;
use PXP\PDF\Fpdf\Core\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Core\Tree\PDFObjectNode;

/**
 * Specialized dictionary for page objects.
 */
final class PageDictionary extends PDFDictionary
{
    public function __construct()
    {
        parent::__construct();
        $this->addEntry('/Type', new PDFName('Page'));
    }

    /**
     * Set parent pages object reference.
     */
    public function setParent(int|PDFObjectNode|PDFReference $parent): self
    {
        if ($parent instanceof PDFObjectNode) {
            $parent = new PDFReference($parent->getObjectNumber());
        } elseif (is_int($parent)) {
            $parent = new PDFReference($parent);
        }

        $this->addEntry('/Parent', $parent);

        return $this;
    }

    /**
     * Set MediaBox.
     *
     * @param array{0: float, 1: float, 2: float, 3: float}|MediaBoxArray $mediaBox
     */
    public function setMediaBox(array|MediaBoxArray $mediaBox): self
    {
        if (is_array($mediaBox)) {
            $mediaBox = new MediaBoxArray($mediaBox);
        }

        $this->addEntry('/MediaBox', $mediaBox);

        return $this;
    }

    /**
     * Get MediaBox.
     */
    public function getMediaBox(): ?MediaBoxArray
    {
        $entry = $this->getEntry('/MediaBox');

        return $entry instanceof MediaBoxArray ? $entry : null;
    }

    /**
     * Set Resources reference.
     */
    public function setResources(int|PDFObjectNode|PDFReference $resources): self
    {
        if ($resources instanceof PDFObjectNode) {
            $resources = new PDFReference($resources->getObjectNumber());
        } elseif (is_int($resources)) {
            $resources = new PDFReference($resources);
        }

        $this->addEntry('/Resources', $resources);

        return $this;
    }

    /**
     * Set Contents reference.
     */
    public function setContents(int|PDFObjectNode|PDFReference $contents): self
    {
        if ($contents instanceof PDFObjectNode) {
            $contents = new PDFReference($contents->getObjectNumber());
        } elseif (is_int($contents)) {
            $contents = new PDFReference($contents);
        }

        $this->addEntry('/Contents', $contents);

        return $this;
    }

    /**
     * Set rotation.
     */
    public function setRotate(int $rotation): self
    {
        $this->addEntry('/Rotate', new PDFNumber($rotation));

        return $this;
    }

    /**
     * Set annotations array.
     *
     * @param array<int> $annotObjectNumbers
     */
    public function setAnnots(array $annotObjectNumbers): self
    {
        $pdfArray = new PDFArray;

        foreach ($annotObjectNumbers as $annotObjectNumber) {
            $pdfArray->add(new PDFReference($annotObjectNumber));
        }

        $this->addEntry('/Annots', $pdfArray);

        return $this;
    }
}
