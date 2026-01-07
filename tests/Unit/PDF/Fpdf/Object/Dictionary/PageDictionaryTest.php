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

use PXP\PDF\Fpdf\Core\Object\Array\MediaBoxArray;
use PXP\PDF\Fpdf\Core\Object\Base\PDFArray;
use PXP\PDF\Fpdf\Core\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Core\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Core\Object\Dictionary\PageDictionary;
use PXP\PDF\Fpdf\Core\Tree\PDFDocument;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Object\Dictionary\PageDictionary
 */
final class PageDictionaryTest extends TestCase
{
    public function testConstructorSetsType(): void
    {
        $pageDictionary = new PageDictionary;
        $type           = $pageDictionary->getEntry('/Type');

        $this->assertNotNull($type);
        $this->assertStringContainsString('Page', (string) $type);
    }

    public function testSetParentWithObjectNode(): void
    {
        $pdfDocument   = new PDFDocument;
        $pdfDictionary = new PDFDictionary;
        $pdfObjectNode = $pdfDocument->addObject($pdfDictionary, 1);

        $pageDictionary = new PageDictionary;
        $pageDictionary->setParent($pdfObjectNode);

        $parent = $pageDictionary->getEntry('/Parent');
        $this->assertInstanceOf(PDFReference::class, $parent);
        $this->assertSame(1, $parent->getObjectNumber());
    }

    public function testSetParentWithReference(): void
    {
        $pageDictionary = new PageDictionary;
        $pageDictionary->setParent(new PDFReference(1));

        $parent = $pageDictionary->getEntry('/Parent');
        $this->assertInstanceOf(PDFReference::class, $parent);
    }

    public function testSetParentWithInteger(): void
    {
        $pageDictionary = new PageDictionary;
        $pageDictionary->setParent(1);

        $parent = $pageDictionary->getEntry('/Parent');
        $this->assertInstanceOf(PDFReference::class, $parent);
    }

    public function testSetMediaBox(): void
    {
        $pageDictionary = new PageDictionary;
        $mediaBoxArray  = new MediaBoxArray([0, 0, 612, 792]);
        $pageDictionary->setMediaBox($mediaBoxArray);

        $mb = $pageDictionary->getMediaBox();
        $this->assertInstanceOf(MediaBoxArray::class, $mb);
    }

    public function testSetMediaBoxWithArray(): void
    {
        $pageDictionary = new PageDictionary;
        $pageDictionary->setMediaBox([0, 0, 612, 792]);

        $mb = $pageDictionary->getMediaBox();
        $this->assertInstanceOf(MediaBoxArray::class, $mb);
    }

    public function testSetResources(): void
    {
        $pageDictionary = new PageDictionary;
        $pageDictionary->setResources(2);

        $resources = $pageDictionary->getEntry('/Resources');
        $this->assertInstanceOf(PDFReference::class, $resources);
        $this->assertSame(2, $resources->getObjectNumber());
    }

    public function testSetContents(): void
    {
        $pageDictionary = new PageDictionary;
        $pageDictionary->setContents(4);

        $contents = $pageDictionary->getEntry('/Contents');
        $this->assertInstanceOf(PDFReference::class, $contents);
        $this->assertSame(4, $contents->getObjectNumber());
    }

    public function testSetRotate(): void
    {
        $pageDictionary = new PageDictionary;
        $pageDictionary->setRotate(90);

        $rotate = $pageDictionary->getEntry('/Rotate');
        $this->assertNotNull($rotate);
        $this->assertSame(90, $rotate->getValue());
    }

    public function testSetAnnots(): void
    {
        $pageDictionary = new PageDictionary;
        $pageDictionary->setAnnots([5, 6, 7]);

        $annots = $pageDictionary->getEntry('/Annots');
        $this->assertInstanceOf(PDFArray::class, $annots);
    }
}
