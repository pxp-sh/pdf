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
use PXP\PDF\Fpdf\Core\Tree\PDFObjectNode;
use PXP\PDF\Fpdf\Core\Tree\PDFObjectRegistry;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Tree\PDFObjectRegistry
 */
final class PDFObjectRegistryTest extends TestCase
{
    public function testRegister(): void
    {
        $pdfObjectRegistry = new PDFObjectRegistry;
        $pdfDictionary     = new PDFDictionary;
        $pdfObjectNode     = new PDFObjectNode(1, $pdfDictionary);

        $pdfObjectRegistry->register($pdfObjectNode);
        $this->assertSame($pdfObjectNode, $pdfObjectRegistry->get(1));
    }

    public function testGetReturnsNullForMissing(): void
    {
        $pdfObjectRegistry = new PDFObjectRegistry;
        $this->assertNull($pdfObjectRegistry->get(999));
    }

    public function testHas(): void
    {
        $pdfObjectRegistry = new PDFObjectRegistry;
        $pdfDictionary     = new PDFDictionary;
        $pdfObjectNode     = new PDFObjectNode(1, $pdfDictionary);

        $pdfObjectRegistry->register($pdfObjectNode);
        $this->assertTrue($pdfObjectRegistry->has(1));
        $this->assertFalse($pdfObjectRegistry->has(999));
    }

    public function testRemove(): void
    {
        $pdfObjectRegistry = new PDFObjectRegistry;
        $pdfDictionary     = new PDFDictionary;
        $pdfObjectNode     = new PDFObjectNode(1, $pdfDictionary);

        $pdfObjectRegistry->register($pdfObjectNode);
        $pdfObjectRegistry->remove(1);
        $this->assertFalse($pdfObjectRegistry->has(1));
    }

    public function testGetNextObjectNumber(): void
    {
        $pdfObjectRegistry = new PDFObjectRegistry;
        $this->assertSame(1, $pdfObjectRegistry->getNextObjectNumber());
        $this->assertSame(2, $pdfObjectRegistry->getNextObjectNumber());
    }

    public function testGetNextObjectNumberUpdatesAfterRegister(): void
    {
        $pdfObjectRegistry = new PDFObjectRegistry;
        $pdfDictionary     = new PDFDictionary;
        $pdfObjectNode     = new PDFObjectNode(5, $pdfDictionary);
        $pdfObjectRegistry->register($pdfObjectNode);

        $this->assertSame(6, $pdfObjectRegistry->getNextObjectNumber());
    }

    public function testRebuildObjectNumbers(): void
    {
        $pdfObjectRegistry = new PDFObjectRegistry;
        $dict1             = new PDFDictionary;
        $dict2             = new PDFDictionary;
        $node1             = new PDFObjectNode(10, $dict1);
        $node2             = new PDFObjectNode(20, $dict2);

        $pdfObjectRegistry->register($node1);
        $pdfObjectRegistry->register($node2);

        $pdfObjectRegistry->rebuildObjectNumbers();

        $this->assertSame(1, $node1->getObjectNumber());
        $this->assertSame(2, $node2->getObjectNumber());
    }

    public function testGetMaxObjectNumber(): void
    {
        $pdfObjectRegistry = new PDFObjectRegistry;
        $this->assertSame(0, $pdfObjectRegistry->getMaxObjectNumber());

        $pdfDictionary = new PDFDictionary;
        $pdfObjectNode = new PDFObjectNode(5, $pdfDictionary);
        $pdfObjectRegistry->register($pdfObjectNode);

        $this->assertSame(5, $pdfObjectRegistry->getMaxObjectNumber());
    }
}
