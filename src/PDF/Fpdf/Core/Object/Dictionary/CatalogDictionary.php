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

use function count;
use function is_int;
use PXP\PDF\Fpdf\Core\Object\Base\PDFArray;
use PXP\PDF\Fpdf\Core\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Core\Object\Base\PDFName;
use PXP\PDF\Fpdf\Core\Object\Base\PDFNull;
use PXP\PDF\Fpdf\Core\Object\Base\PDFNumber;
use PXP\PDF\Fpdf\Core\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Core\Tree\PDFObjectNode;

/**
 * Specialized dictionary for catalog (root) object.
 */
final class CatalogDictionary extends PDFDictionary
{
    public function __construct()
    {
        parent::__construct();
        $this->addEntry('/Type', new PDFName('Catalog'));
    }

    /**
     * Set Pages reference.
     */
    public function setPages(int|PDFObjectNode|PDFReference $pages): self
    {
        if ($pages instanceof PDFObjectNode) {
            $pages = new PDFReference($pages->getObjectNumber());
        } elseif (is_int($pages)) {
            $pages = new PDFReference($pages);
        }

        $this->addEntry('/Pages', $pages);

        return $this;
    }

    /**
     * Set OpenAction (viewer preferences).
     *
     * @param array{0: int|PDFReference, 1: string, 2?: null|float, 3?: null|float, 4?: null|float} $action
     */
    public function setOpenAction(array $action): self
    {
        $pdfArray = new PDFArray;

        // Page reference
        if (is_int($action[0])) {
            $pdfArray->add(new PDFReference($action[0]));
        } else {
            $pdfArray->add($action[0]);
        }

        // Action type
        $pdfArray->add(new PDFName($action[1]));
        $counter = count($action);

        // Optional parameters
        for ($i = 2; $i < $counter; $i++) {
            if ($action[$i] === null) {
                $pdfArray->add(new PDFNull);
            } else {
                $pdfArray->add(new PDFNumber($action[$i]));
            }
        }

        $this->addEntry('/OpenAction', $pdfArray);

        return $this;
    }

    /**
     * Set PageLayout.
     */
    public function setPageLayout(string $layout): self
    {
        $this->addEntry('/PageLayout', new PDFName($layout));

        return $this;
    }

    /**
     * Set PageMode.
     */
    public function setPageMode(string $mode): self
    {
        $this->addEntry('/PageMode', new PDFName($mode));

        return $this;
    }
}
