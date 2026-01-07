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

use PXP\PDF\Fpdf\Core\Object\Base\PDFArray;
use PXP\PDF\Fpdf\Core\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Core\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Core\Object\Dictionary\CatalogDictionary;
use PXP\PDF\Fpdf\Core\Tree\PDFDocument;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Object\Dictionary\CatalogDictionary
 */
final class CatalogDictionaryTest extends TestCase
{
    public function testConstructorSetsType(): void
    {
        $catalogDictionary = new CatalogDictionary;
        $type              = $catalogDictionary->getEntry('/Type');

        $this->assertNotNull($type);
        $this->assertStringContainsString('Catalog', (string) $type);
    }

    public function testSetPages(): void
    {
        $catalogDictionary = new CatalogDictionary;
        $catalogDictionary->setPages(1);

        $pages = $catalogDictionary->getEntry('/Pages');
        $this->assertInstanceOf(PDFReference::class, $pages);
        $this->assertSame(1, $pages->getObjectNumber());
    }

    public function testSetPagesWithObjectNode(): void
    {
        $pdfDocument   = new PDFDocument;
        $pdfDictionary = new PDFDictionary;
        $pdfObjectNode = $pdfDocument->addObject($pdfDictionary, 1);

        $catalogDictionary = new CatalogDictionary;
        $catalogDictionary->setPages($pdfObjectNode);

        $pages = $catalogDictionary->getEntry('/Pages');
        $this->assertInstanceOf(PDFReference::class, $pages);
    }

    public function testSetOpenAction(): void
    {
        $catalogDictionary = new CatalogDictionary;
        $catalogDictionary->setOpenAction([1, 'Fit']);

        $openAction = $catalogDictionary->getEntry('/OpenAction');
        $this->assertInstanceOf(PDFArray::class, $openAction);
    }

    public function testSetPageLayout(): void
    {
        $catalogDictionary = new CatalogDictionary;
        $catalogDictionary->setPageLayout('SinglePage');

        $layout = $catalogDictionary->getEntry('/PageLayout');
        $this->assertNotNull($layout);
    }

    public function testSetPageMode(): void
    {
        $catalogDictionary = new CatalogDictionary;
        $catalogDictionary->setPageMode('UseOutlines');

        $mode = $catalogDictionary->getEntry('/PageMode');
        $this->assertNotNull($mode);
    }
}
