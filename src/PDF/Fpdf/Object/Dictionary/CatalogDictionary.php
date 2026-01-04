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

use function count;
use function is_int;
use PXP\PDF\Fpdf\Object\Base\PDFArray;
use PXP\PDF\Fpdf\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Object\Base\PDFName;
use PXP\PDF\Fpdf\Object\Base\PDFNull;
use PXP\PDF\Fpdf\Object\Base\PDFNumber;
use PXP\PDF\Fpdf\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Tree\PDFObjectNode;

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
        $actionArray = new PDFArray;

        // Page reference
        if (is_int($action[0])) {
            $actionArray->add(new PDFReference($action[0]));
        } else {
            $actionArray->add($action[0]);
        }

        // Action type
        $actionArray->add(new PDFName($action[1]));

        // Optional parameters
        for ($i = 2; $i < count($action); $i++) {
            if ($action[$i] === null) {
                $actionArray->add(new PDFNull);
            } else {
                $actionArray->add(new PDFNumber($action[$i]));
            }
        }

        $this->addEntry('/OpenAction', $actionArray);

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
