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

namespace Test\Unit\PDF\Fpdf\Object\Dictionary;

use Test\TestCase;
use PXP\PDF\Fpdf\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Object\Dictionary\CatalogDictionary;
use PXP\PDF\Fpdf\Tree\PDFDocument;

/**
 * @covers \PXP\PDF\Fpdf\Object\Dictionary\CatalogDictionary
 */
final class CatalogDictionaryTest extends TestCase
{
    public function testConstructorSetsType(): void
    {
        $catalog = new CatalogDictionary();
        $type = $catalog->getEntry('/Type');

        $this->assertNotNull($type);
        $this->assertStringContainsString('Catalog', (string) $type);
    }

    public function testSetPages(): void
    {
        $catalog = new CatalogDictionary();
        $catalog->setPages(1);

        $pages = $catalog->getEntry('/Pages');
        $this->assertInstanceOf(PDFReference::class, $pages);
        $this->assertSame(1, $pages->getObjectNumber());
    }

    public function testSetPagesWithObjectNode(): void
    {
        $doc = new PDFDocument();
        $pagesDict = new \PXP\PDF\Fpdf\Object\Base\PDFDictionary();
        $pagesNode = $doc->addObject($pagesDict, 1);

        $catalog = new CatalogDictionary();
        $catalog->setPages($pagesNode);

        $pages = $catalog->getEntry('/Pages');
        $this->assertInstanceOf(PDFReference::class, $pages);
    }

    public function testSetOpenAction(): void
    {
        $catalog = new CatalogDictionary();
        $catalog->setOpenAction([1, 'Fit']);

        $openAction = $catalog->getEntry('/OpenAction');
        $this->assertInstanceOf(\PXP\PDF\Fpdf\Object\Base\PDFArray::class, $openAction);
    }

    public function testSetPageLayout(): void
    {
        $catalog = new CatalogDictionary();
        $catalog->setPageLayout('SinglePage');

        $layout = $catalog->getEntry('/PageLayout');
        $this->assertNotNull($layout);
    }

    public function testSetPageMode(): void
    {
        $catalog = new CatalogDictionary();
        $catalog->setPageMode('UseOutlines');

        $mode = $catalog->getEntry('/PageMode');
        $this->assertNotNull($mode);
    }
}
