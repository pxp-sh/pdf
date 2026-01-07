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

use PXP\PDF\Fpdf\Core\Object\Base\PDFString;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Object\Base\PDFString
 */
final class PDFStringTest extends TestCase
{
    public function testToStringWithLiteralString(): void
    {
        $pdfString = new PDFString('test');
        $result    = (string) $pdfString;
        $this->assertStringStartsWith('(', $result);
        $this->assertStringEndsWith(')', $result);
        $this->assertStringContainsString('test', $result);
    }

    public function testToStringWithHexString(): void
    {
        $pdfString = new PDFString('test', true);
        $result    = (string) $pdfString;
        $this->assertStringStartsWith('<', $result);
        $this->assertStringEndsWith('>', $result);
    }

    public function testEscapesSpecialCharacters(): void
    {
        $pdfString = new PDFString('test(ing)');
        $result    = (string) $pdfString;
        $this->assertStringContainsString('\\(', $result);
        $this->assertStringContainsString('\\)', $result);
    }

    public function testGetValue(): void
    {
        $pdfString = new PDFString('test');
        $this->assertSame('test', $pdfString->getValue());
    }

    public function testIsHex(): void
    {
        $string1 = new PDFString('test', false);
        $string2 = new PDFString('test', true);

        $this->assertFalse($string1->isHex());
        $this->assertTrue($string2->isHex());
    }

    public function testFromPDFStringWithLiteralString(): void
    {
        $pdfString = PDFString::fromPDFString('(test)');
        $this->assertSame('test', $pdfString->getValue());
        $this->assertFalse($pdfString->isHex());
    }

    public function testFromPDFStringWithHexString(): void
    {
        $pdfString = PDFString::fromPDFString('<74657374>');
        $this->assertSame('test', $pdfString->getValue());
        $this->assertTrue($pdfString->isHex());
    }

    public function testSetValue(): void
    {
        $pdfString = new PDFString('old');
        $pdfString->setValue('new', 'UTF-8');
        $this->assertSame('new', $pdfString->getValue());
    }
}
