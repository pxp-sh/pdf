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

use PXP\PDF\Fpdf\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Tree\PDFObjectNode;
use PXP\PDF\Fpdf\Tree\PDFObjectRegistry;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Tree\PDFObjectRegistry
 */
final class PDFObjectRegistryTest extends TestCase
{
    public function testRegister(): void
    {
        $registry = new PDFObjectRegistry;
        $dict     = new PDFDictionary;
        $node     = new PDFObjectNode(1, $dict);

        $registry->register($node);
        $this->assertSame($node, $registry->get(1));
    }

    public function testGetReturnsNullForMissing(): void
    {
        $registry = new PDFObjectRegistry;
        $this->assertNull($registry->get(999));
    }

    public function testHas(): void
    {
        $registry = new PDFObjectRegistry;
        $dict     = new PDFDictionary;
        $node     = new PDFObjectNode(1, $dict);

        $registry->register($node);
        $this->assertTrue($registry->has(1));
        $this->assertFalse($registry->has(999));
    }

    public function testRemove(): void
    {
        $registry = new PDFObjectRegistry;
        $dict     = new PDFDictionary;
        $node     = new PDFObjectNode(1, $dict);

        $registry->register($node);
        $registry->remove(1);
        $this->assertFalse($registry->has(1));
    }

    public function testGetNextObjectNumber(): void
    {
        $registry = new PDFObjectRegistry;
        $this->assertSame(1, $registry->getNextObjectNumber());
        $this->assertSame(2, $registry->getNextObjectNumber());
    }

    public function testGetNextObjectNumberUpdatesAfterRegister(): void
    {
        $registry = new PDFObjectRegistry;
        $dict     = new PDFDictionary;
        $node     = new PDFObjectNode(5, $dict);
        $registry->register($node);

        $this->assertSame(6, $registry->getNextObjectNumber());
    }

    public function testRebuildObjectNumbers(): void
    {
        $registry = new PDFObjectRegistry;
        $dict1    = new PDFDictionary;
        $dict2    = new PDFDictionary;
        $node1    = new PDFObjectNode(10, $dict1);
        $node2    = new PDFObjectNode(20, $dict2);

        $registry->register($node1);
        $registry->register($node2);

        $registry->rebuildObjectNumbers();

        $this->assertSame(1, $node1->getObjectNumber());
        $this->assertSame(2, $node2->getObjectNumber());
    }

    public function testGetMaxObjectNumber(): void
    {
        $registry = new PDFObjectRegistry;
        $this->assertSame(0, $registry->getMaxObjectNumber());

        $dict = new PDFDictionary;
        $node = new PDFObjectNode(5, $dict);
        $registry->register($node);

        $this->assertSame(5, $registry->getMaxObjectNumber());
    }
}
