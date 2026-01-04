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

use PXP\PDF\Fpdf\Object\Base\PDFString;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Object\Base\PDFString
 */
final class PDFStringTest extends TestCase
{
    public function testToStringWithLiteralString(): void
    {
        $string = new PDFString('test');
        $result = (string) $string;
        $this->assertStringStartsWith('(', $result);
        $this->assertStringEndsWith(')', $result);
        $this->assertStringContainsString('test', $result);
    }

    public function testToStringWithHexString(): void
    {
        $string = new PDFString('test', true);
        $result = (string) $string;
        $this->assertStringStartsWith('<', $result);
        $this->assertStringEndsWith('>', $result);
    }

    public function testEscapesSpecialCharacters(): void
    {
        $string = new PDFString('test(ing)');
        $result = (string) $string;
        $this->assertStringContainsString('\\(', $result);
        $this->assertStringContainsString('\\)', $result);
    }

    public function testGetValue(): void
    {
        $string = new PDFString('test');
        $this->assertSame('test', $string->getValue());
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
        $string = PDFString::fromPDFString('(test)');
        $this->assertSame('test', $string->getValue());
        $this->assertFalse($string->isHex());
    }

    public function testFromPDFStringWithHexString(): void
    {
        $string = PDFString::fromPDFString('<74657374>');
        $this->assertSame('test', $string->getValue());
        $this->assertTrue($string->isHex());
    }

    public function testSetValue(): void
    {
        $string = new PDFString('old');
        $string->setValue('new', 'UTF-8');
        $this->assertSame('new', $string->getValue());
    }
}
