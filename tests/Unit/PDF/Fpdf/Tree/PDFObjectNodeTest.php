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
use PXP\PDF\Fpdf\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Tree\PDFObjectNode;
use PXP\PDF\Fpdf\Tree\PDFObjectRegistry;

/**
 * @covers \PXP\PDF\Fpdf\Tree\PDFObjectNode
 */
final class PDFObjectNodeTest extends TestCase
{
    public function testGetObjectNumber(): void
    {
        $dict = new PDFDictionary();
        $node = new PDFObjectNode(5, $dict);

        $this->assertSame(5, $node->getObjectNumber());
    }

    public function testSetObjectNumber(): void
    {
        $dict = new PDFDictionary();
        $node = new PDFObjectNode(5, $dict);
        $node->setObjectNumber(10);

        $this->assertSame(10, $node->getObjectNumber());
    }

    public function testGetGenerationNumber(): void
    {
        $dict = new PDFDictionary();
        $node = new PDFObjectNode(5, $dict, 3);

        $this->assertSame(3, $node->getGenerationNumber());
    }

    public function testGetValue(): void
    {
        $dict = new PDFDictionary();
        $node = new PDFObjectNode(1, $dict);

        $this->assertSame($dict, $node->getValue());
    }

    public function testSetValue(): void
    {
        $dict1 = new PDFDictionary();
        $dict2 = new PDFDictionary();
        $node = new PDFObjectNode(1, $dict1);
        $node->setValue($dict2);

        $this->assertSame($dict2, $node->getValue());
    }

    public function testToString(): void
    {
        $dict = new PDFDictionary();
        $node = new PDFObjectNode(3, $dict, 0);

        $result = (string) $node;
        $this->assertStringContainsString('3 0 obj', $result);
        $this->assertStringContainsString('endobj', $result);
    }

    public function testResolveReference(): void
    {
        $registry = new PDFObjectRegistry();
        $dict = new PDFDictionary();
        $node = new PDFObjectNode(5, $dict);
        $registry->register($node);

        $ref = new PDFReference(5);
        $resolved = $node->resolveReference($ref, $registry);

        $this->assertSame($node, $resolved);
    }
}
