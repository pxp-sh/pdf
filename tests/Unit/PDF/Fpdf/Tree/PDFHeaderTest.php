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

use PXP\PDF\Fpdf\Core\Tree\PDFHeader;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Tree\PDFHeader
 */
final class PDFHeaderTest extends TestCase
{
    public function testConstructorWithDefaultVersion(): void
    {
        $header = new PDFHeader;
        $this->assertSame('1.3', $header->getVersion());
    }

    public function testConstructorWithCustomVersion(): void
    {
        $header = new PDFHeader('1.4');
        $this->assertSame('1.4', $header->getVersion());
    }

    public function testSetVersion(): void
    {
        $header = new PDFHeader;
        $header->setVersion('1.5');
        $this->assertSame('1.5', $header->getVersion());
    }

    public function testToString(): void
    {
        $header = new PDFHeader('1.4');
        $result = (string) $header;
        $this->assertStringStartsWith('%PDF-1.4', $result);
        $this->assertStringEndsWith("\n", $result);
    }

    public function testParse(): void
    {
        $content = '%PDF-1.4\n';
        $header  = PDFHeader::parse($content);

        $this->assertNotNull($header);
        $this->assertSame('1.4', $header->getVersion());
    }

    public function testParseReturnsNullForInvalidContent(): void
    {
        $content = 'Invalid content';
        $header  = PDFHeader::parse($content);

        $this->assertNull($header);
    }
}
