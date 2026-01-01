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

namespace Test\Unit\PDF\Fpdf\Tree;

use Test\TestCase;
use PXP\PDF\Fpdf\Exception\FpdfException;
use PXP\PDF\Fpdf\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Object\Base\PDFName;
use PXP\PDF\Fpdf\Object\Base\PDFNumber;
use PXP\PDF\Fpdf\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Object\Dictionary\CatalogDictionary;
use PXP\PDF\Fpdf\Tree\PDFDocument;

/**
 * @covers \PXP\PDF\Fpdf\Tree\PDFDocument
 */
final class PDFDocumentTest extends TestCase
{
    public function testConstructorSetsDefaultVersion(): void
    {
        $doc = new PDFDocument();
        $this->assertSame('1.3', $doc->getHeader()->getVersion());
    }

    public function testConstructorWithCustomVersion(): void
    {
        $doc = new PDFDocument('1.4');
        $this->assertSame('1.4', $doc->getHeader()->getVersion());
    }

    public function testAddObject(): void
    {
        $doc = new PDFDocument();
        $dict = new PDFDictionary();
        $node = $doc->addObject($dict);

        $this->assertSame(1, $node->getObjectNumber());
        $this->assertSame($dict, $node->getValue());
    }

    public function testAddObjectWithCustomNumber(): void
    {
        $doc = new PDFDocument();
        $dict = new PDFDictionary();
        $node = $doc->addObject($dict, 5);

        $this->assertSame(5, $node->getObjectNumber());
    }

    public function testGetObject(): void
    {
        $doc = new PDFDocument();
        $dict = new PDFDictionary();
        $node = $doc->addObject($dict, 3);

        $retrieved = $doc->getObject(3);
        $this->assertSame($node, $retrieved);
    }

    public function testGetObjectReturnsNullForMissing(): void
    {
        $doc = new PDFDocument();
        $this->assertNull($doc->getObject(999));
    }

    public function testRemoveObject(): void
    {
        $doc = new PDFDocument();
        $dict = new PDFDictionary();
        $node = $doc->addObject($dict, 3);

        $doc->removeObject(3);
        $this->assertNull($doc->getObject(3));
    }

    public function testSetRoot(): void
    {
        $doc = new PDFDocument();
        $catalog = new CatalogDictionary();
        $catalogNode = $doc->addObject($catalog, 5);

        $doc->setRoot($catalogNode);
        $this->assertSame($catalogNode, $doc->getRoot());
    }

    public function testSerializeCreatesValidPDF(): void
    {
        $doc = new PDFDocument();
        $catalog = new CatalogDictionary();
        $catalogNode = $doc->addObject($catalog, 5);
        $doc->setRoot($catalogNode);

        $pdf = $doc->serialize();
        $this->assertStringStartsWith('%PDF-1.3', $pdf);
        $this->assertStringContainsString('xref', $pdf);
        $this->assertStringContainsString('trailer', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
    }

    public function testParseFromFileThrowsExceptionForMissingFile(): void
    {
        $this->expectException(FpdfException::class);
        PDFDocument::parseFromFile('/nonexistent/file.pdf');
    }

    public function testGetPagesReturnsNullWhenNoPages(): void
    {
        $doc = new PDFDocument();
        $this->assertNull($doc->getPages());
    }

    public function testGetPageReturnsNullForInvalidPageNumber(): void
    {
        $doc = new PDFDocument();
        $this->assertNull($doc->getPage(1));
    }
}
