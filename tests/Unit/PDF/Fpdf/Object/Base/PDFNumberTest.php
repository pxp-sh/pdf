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

use PXP\PDF\Fpdf\Core\Object\Base\PDFNumber;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Object\Base\PDFNumber
 */
final class PDFNumberTest extends TestCase
{
    public function testToStringWithInteger(): void
    {
        $pdfNumber = new PDFNumber(42);
        $this->assertSame('42', (string) $pdfNumber);
    }

    public function testToStringWithFloat(): void
    {
        $pdfNumber = new PDFNumber(42.5);
        $this->assertStringContainsString('42.5', (string) $pdfNumber);
    }

    public function testToStringWithFloatRemovesTrailingZeros(): void
    {
        $pdfNumber = new PDFNumber(42.0);
        $result    = (string) $pdfNumber;
        $this->assertStringNotContainsString('.0', $result);
    }

    public function testGetValueReturnsInteger(): void
    {
        $pdfNumber = new PDFNumber(42);
        $this->assertSame(42, $pdfNumber->getValue());
    }

    public function testGetValueReturnsFloat(): void
    {
        $pdfNumber = new PDFNumber(42.5);
        $this->assertSame(42.5, $pdfNumber->getValue());
    }
}
