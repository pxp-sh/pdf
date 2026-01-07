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
namespace Test\Unit\PDF\Fpdf\Object\Base;

use PXP\PDF\Fpdf\Core\Object\Base\PDFName;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Object\Base\PDFName
 */
final class PDFNameTest extends TestCase
{
    public function testToStringReturnsNameWithSlash(): void
    {
        $pdfName = new PDFName('Type');
        $this->assertSame('/Type', (string) $pdfName);
    }

    public function testToStringRemovesLeadingSlash(): void
    {
        $pdfName = new PDFName('/Type');
        $this->assertSame('/Type', (string) $pdfName);
    }

    public function testToStringEscapesSpecialCharacters(): void
    {
        $pdfName = new PDFName('Test#Name');
        $this->assertSame('/Test#23Name', (string) $pdfName);
    }

    public function testGetNameReturnsNameWithoutSlash(): void
    {
        $pdfName = new PDFName('/Type');
        $this->assertSame('Type', $pdfName->getName());
    }

    public function testEscapesAllSpecialCharacters(): void
    {
        $pdfName = new PDFName('Test#(Name)');
        $result  = (string) $pdfName;
        $this->assertStringContainsString('#23', $result);
        $this->assertStringContainsString('#28', $result);
        $this->assertStringContainsString('#29', $result);
    }
}
