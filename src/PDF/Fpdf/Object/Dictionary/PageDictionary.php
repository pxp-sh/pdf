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

use PXP\PDF\Fpdf\Object\Array\KidsArray;
use PXP\PDF\Fpdf\Object\Array\MediaBoxArray;
use PXP\PDF\Fpdf\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Object\Base\PDFName;
use PXP\PDF\Fpdf\Object\Base\PDFNumber;
use PXP\PDF\Fpdf\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Tree\PDFObjectNode;

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
    public function setParent(PDFObjectNode|PDFReference|int $parent): self
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
     * @param MediaBoxArray|array{0: float, 1: float, 2: float, 3: float} $mediaBox
     */
    public function setMediaBox(MediaBoxArray|array $mediaBox): self
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
    public function setResources(PDFObjectNode|PDFReference|int $resources): self
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
    public function setContents(PDFObjectNode|PDFReference|int $contents): self
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
        $annots = new \PXP\PDF\Fpdf\Object\Base\PDFArray();
        foreach ($annotObjectNumbers as $objNum) {
            $annots->add(new PDFReference($objNum));
        }

        $this->addEntry('/Annots', $annots);

        return $this;
    }
}
