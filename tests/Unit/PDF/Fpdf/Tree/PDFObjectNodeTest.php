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
use PXP\PDF\Fpdf\Core\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Core\Tree\PDFObjectNode;
use PXP\PDF\Fpdf\Core\Tree\PDFObjectRegistry;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Tree\PDFObjectNode
 */
final class PDFObjectNodeTest extends TestCase
{
    public function testGetObjectNumber(): void
    {
        $pdfDictionary = new PDFDictionary;
        $pdfObjectNode = new PDFObjectNode(5, $pdfDictionary);

        $this->assertSame(5, $pdfObjectNode->getObjectNumber());
    }

    public function testSetObjectNumber(): void
    {
        $pdfDictionary = new PDFDictionary;
        $pdfObjectNode = new PDFObjectNode(5, $pdfDictionary);
        $pdfObjectNode->setObjectNumber(10);

        $this->assertSame(10, $pdfObjectNode->getObjectNumber());
    }

    public function testGetGenerationNumber(): void
    {
        $pdfDictionary = new PDFDictionary;
        $pdfObjectNode = new PDFObjectNode(5, $pdfDictionary, 3);

        $this->assertSame(3, $pdfObjectNode->getGenerationNumber());
    }

    public function testGetValue(): void
    {
        $pdfDictionary = new PDFDictionary;
        $pdfObjectNode = new PDFObjectNode(1, $pdfDictionary);

        $this->assertSame($pdfDictionary, $pdfObjectNode->getValue());
    }

    public function testSetValue(): void
    {
        $dict1         = new PDFDictionary;
        $dict2         = new PDFDictionary;
        $pdfObjectNode = new PDFObjectNode(1, $dict1);
        $pdfObjectNode->setValue($dict2);

        $this->assertSame($dict2, $pdfObjectNode->getValue());
    }

    public function testToString(): void
    {
        $pdfDictionary = new PDFDictionary;
        $pdfObjectNode = new PDFObjectNode(3, $pdfDictionary, 0);

        $result = (string) $pdfObjectNode;
        $this->assertStringContainsString('3 0 obj', $result);
        $this->assertStringContainsString('endobj', $result);
    }

    public function testResolveReference(): void
    {
        $pdfObjectRegistry = new PDFObjectRegistry;
        $pdfDictionary     = new PDFDictionary;
        $pdfObjectNode     = new PDFObjectNode(5, $pdfDictionary);
        $pdfObjectRegistry->register($pdfObjectNode);

        $pdfReference = new PDFReference(5);
        $resolved     = $pdfObjectNode->resolveReference($pdfReference, $pdfObjectRegistry);

        $this->assertSame($pdfObjectNode, $resolved);
    }
}
