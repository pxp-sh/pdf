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

use PXP\PDF\Fpdf\Object\Array\MediaBoxArray;
use PXP\PDF\Fpdf\Object\Base\PDFArray;
use PXP\PDF\Fpdf\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Object\Dictionary\PageDictionary;
use PXP\PDF\Fpdf\Tree\PDFDocument;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Object\Dictionary\PageDictionary
 */
final class PageDictionaryTest extends TestCase
{
    public function testConstructorSetsType(): void
    {
        $page = new PageDictionary;
        $type = $page->getEntry('/Type');

        $this->assertNotNull($type);
        $this->assertStringContainsString('Page', (string) $type);
    }

    public function testSetParentWithObjectNode(): void
    {
        $doc        = new PDFDocument;
        $parentDict = new PDFDictionary;
        $parentNode = $doc->addObject($parentDict, 1);

        $page = new PageDictionary;
        $page->setParent($parentNode);

        $parent = $page->getEntry('/Parent');
        $this->assertInstanceOf(PDFReference::class, $parent);
        $this->assertSame(1, $parent->getObjectNumber());
    }

    public function testSetParentWithReference(): void
    {
        $page = new PageDictionary;
        $page->setParent(new PDFReference(1));

        $parent = $page->getEntry('/Parent');
        $this->assertInstanceOf(PDFReference::class, $parent);
    }

    public function testSetParentWithInteger(): void
    {
        $page = new PageDictionary;
        $page->setParent(1);

        $parent = $page->getEntry('/Parent');
        $this->assertInstanceOf(PDFReference::class, $parent);
    }

    public function testSetMediaBox(): void
    {
        $page     = new PageDictionary;
        $mediaBox = new MediaBoxArray([0, 0, 612, 792]);
        $page->setMediaBox($mediaBox);

        $mb = $page->getMediaBox();
        $this->assertInstanceOf(MediaBoxArray::class, $mb);
    }

    public function testSetMediaBoxWithArray(): void
    {
        $page = new PageDictionary;
        $page->setMediaBox([0, 0, 612, 792]);

        $mb = $page->getMediaBox();
        $this->assertInstanceOf(MediaBoxArray::class, $mb);
    }

    public function testSetResources(): void
    {
        $page = new PageDictionary;
        $page->setResources(2);

        $resources = $page->getEntry('/Resources');
        $this->assertInstanceOf(PDFReference::class, $resources);
        $this->assertSame(2, $resources->getObjectNumber());
    }

    public function testSetContents(): void
    {
        $page = new PageDictionary;
        $page->setContents(4);

        $contents = $page->getEntry('/Contents');
        $this->assertInstanceOf(PDFReference::class, $contents);
        $this->assertSame(4, $contents->getObjectNumber());
    }

    public function testSetRotate(): void
    {
        $page = new PageDictionary;
        $page->setRotate(90);

        $rotate = $page->getEntry('/Rotate');
        $this->assertNotNull($rotate);
        $this->assertSame(90, $rotate->getValue());
    }

    public function testSetAnnots(): void
    {
        $page = new PageDictionary;
        $page->setAnnots([5, 6, 7]);

        $annots = $page->getEntry('/Annots');
        $this->assertInstanceOf(PDFArray::class, $annots);
    }
}
