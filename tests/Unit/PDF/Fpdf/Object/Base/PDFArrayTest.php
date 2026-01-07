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

use PXP\PDF\Fpdf\Core\Object\Base\PDFArray;
use PXP\PDF\Fpdf\Core\Object\Base\PDFNumber;
use PXP\PDF\Fpdf\Core\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Core\Object\Base\PDFString;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Object\Base\PDFArray
 */
final class PDFArrayTest extends TestCase
{
    public function testToStringWithEmptyArray(): void
    {
        $pdfArray = new PDFArray;
        $this->assertSame('[]', (string) $pdfArray);
    }

    public function testAddItems(): void
    {
        $pdfArray = new PDFArray;
        $pdfArray->add(new PDFNumber(1));
        $pdfArray->add(new PDFNumber(2));
        $pdfArray->add(new PDFNumber(3));

        $this->assertSame(3, $pdfArray->count());
        $this->assertStringContainsString('1', (string) $pdfArray);
        $this->assertStringContainsString('2', (string) $pdfArray);
        $this->assertStringContainsString('3', (string) $pdfArray);
    }

    public function testAddAutoConvertsStrings(): void
    {
        $pdfArray = new PDFArray;
        $pdfArray->add('test');
        $pdfArray->add(42);

        $this->assertSame(2, $pdfArray->count());
        $item = $pdfArray->get(0);
        $this->assertInstanceOf(PDFString::class, $item);
    }

    public function testGetItem(): void
    {
        $pdfArray = new PDFArray;
        $pdfArray->add(new PDFNumber(42));
        $item = $pdfArray->get(0);

        $this->assertInstanceOf(PDFNumber::class, $item);
        $this->assertSame(42, $item->getValue());
    }

    public function testGetItemReturnsNullForInvalidIndex(): void
    {
        $pdfArray = new PDFArray;
        $this->assertNull($pdfArray->get(999));
    }

    public function testSetItem(): void
    {
        $pdfArray = new PDFArray;
        $pdfArray->add(new PDFNumber(1));
        $pdfArray->set(0, new PDFNumber(42));

        $item = $pdfArray->get(0);
        $this->assertInstanceOf(PDFNumber::class, $item);
        $this->assertSame(42, $item->getValue());
    }

    public function testConstructorWithItems(): void
    {
        $pdfArray = new PDFArray([
            new PDFNumber(1),
            new PDFNumber(2),
            new PDFNumber(3),
        ]);

        $this->assertSame(3, $pdfArray->count());
    }

    public function testToStringWithMixedTypes(): void
    {
        $pdfArray = new PDFArray;
        $pdfArray->add(new PDFNumber(1));
        $pdfArray->add(new PDFString('test'));
        $pdfArray->add(new PDFReference(3, 0));

        $result = (string) $pdfArray;
        $this->assertStringStartsWith('[', $result);
        $this->assertStringEndsWith(']', $result);
        $this->assertStringContainsString('1', $result);
        $this->assertStringContainsString('test', $result);
        $this->assertStringContainsString('3 0 R', $result);
    }
}
