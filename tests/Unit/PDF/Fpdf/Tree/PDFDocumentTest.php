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

use PXP\PDF\Fpdf\Core\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Core\Object\Dictionary\CatalogDictionary;
use PXP\PDF\Fpdf\Core\Tree\PDFDocument;
use PXP\PDF\Fpdf\Exceptions\Exception\FpdfException;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Tree\PDFDocument
 */
final class PDFDocumentTest extends TestCase
{
    public function testConstructorSetsDefaultVersion(): void
    {
        $pdfDocument = new PDFDocument;
        $this->assertSame('1.3', $pdfDocument->getHeader()->getVersion());
    }

    public function testConstructorWithCustomVersion(): void
    {
        $pdfDocument = new PDFDocument('1.4');
        $this->assertSame('1.4', $pdfDocument->getHeader()->getVersion());
    }

    public function testAddObject(): void
    {
        $pdfDocument   = new PDFDocument;
        $pdfDictionary = new PDFDictionary;
        $pdfObjectNode = $pdfDocument->addObject($pdfDictionary);

        $this->assertSame(1, $pdfObjectNode->getObjectNumber());
        $this->assertSame($pdfDictionary, $pdfObjectNode->getValue());
    }

    public function testAddObjectWithCustomNumber(): void
    {
        $pdfDocument   = new PDFDocument;
        $pdfDictionary = new PDFDictionary;
        $pdfObjectNode = $pdfDocument->addObject($pdfDictionary, 5);

        $this->assertSame(5, $pdfObjectNode->getObjectNumber());
    }

    public function testGetObject(): void
    {
        $pdfDocument   = new PDFDocument;
        $pdfDictionary = new PDFDictionary;
        $pdfObjectNode = $pdfDocument->addObject($pdfDictionary, 3);

        $retrieved = $pdfDocument->getObject(3);
        $this->assertSame($pdfObjectNode, $retrieved);
    }

    public function testGetObjectReturnsNullForMissing(): void
    {
        $pdfDocument = new PDFDocument;
        $this->assertNull($pdfDocument->getObject(999));
    }

    public function testRemoveObject(): void
    {
        $pdfDocument   = new PDFDocument;
        $pdfDictionary = new PDFDictionary;
        $pdfDocument->addObject($pdfDictionary, 3);

        $pdfDocument->removeObject(3);
        $this->assertNull($pdfDocument->getObject(3));
    }

    public function testSetRoot(): void
    {
        $pdfDocument       = new PDFDocument;
        $catalogDictionary = new CatalogDictionary;
        $pdfObjectNode     = $pdfDocument->addObject($catalogDictionary, 5);

        $pdfDocument->setRoot($pdfObjectNode);
        $this->assertSame($pdfObjectNode, $pdfDocument->getRoot());
    }

    public function testSerializeCreatesValidPDF(): void
    {
        $pdfDocument       = new PDFDocument;
        $catalogDictionary = new CatalogDictionary;
        $pdfObjectNode     = $pdfDocument->addObject($catalogDictionary, 5);
        $pdfDocument->setRoot($pdfObjectNode);

        $pdf = $pdfDocument->serialize();
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
        $pdfDocument = new PDFDocument;
        $this->assertNull($pdfDocument->getPages());
    }

    public function testGetPageReturnsNullForInvalidPageNumber(): void
    {
        $pdfDocument = new PDFDocument;
        $this->assertNull($pdfDocument->getPage(1));
    }
}
