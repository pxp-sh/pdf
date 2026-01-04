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

use PXP\PDF\Fpdf\Object\Base\PDFArray;
use PXP\PDF\Fpdf\Object\Base\PDFNumber;
use PXP\PDF\Fpdf\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Object\Base\PDFString;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Object\Base\PDFArray
 */
final class PDFArrayTest extends TestCase
{
    public function testToStringWithEmptyArray(): void
    {
        $array = new PDFArray;
        $this->assertSame('[]', (string) $array);
    }

    public function testAddItems(): void
    {
        $array = new PDFArray;
        $array->add(new PDFNumber(1));
        $array->add(new PDFNumber(2));
        $array->add(new PDFNumber(3));

        $this->assertSame(3, $array->count());
        $this->assertStringContainsString('1', (string) $array);
        $this->assertStringContainsString('2', (string) $array);
        $this->assertStringContainsString('3', (string) $array);
    }

    public function testAddAutoConvertsStrings(): void
    {
        $array = new PDFArray;
        $array->add('test');
        $array->add(42);

        $this->assertSame(2, $array->count());
        $item = $array->get(0);
        $this->assertInstanceOf(PDFString::class, $item);
    }

    public function testGetItem(): void
    {
        $array = new PDFArray;
        $array->add(new PDFNumber(42));
        $item = $array->get(0);

        $this->assertInstanceOf(PDFNumber::class, $item);
        $this->assertSame(42, $item->getValue());
    }

    public function testGetItemReturnsNullForInvalidIndex(): void
    {
        $array = new PDFArray;
        $this->assertNull($array->get(999));
    }

    public function testSetItem(): void
    {
        $array = new PDFArray;
        $array->add(new PDFNumber(1));
        $array->set(0, new PDFNumber(42));

        $item = $array->get(0);
        $this->assertInstanceOf(PDFNumber::class, $item);
        $this->assertSame(42, $item->getValue());
    }

    public function testConstructorWithItems(): void
    {
        $array = new PDFArray([
            new PDFNumber(1),
            new PDFNumber(2),
            new PDFNumber(3),
        ]);

        $this->assertSame(3, $array->count());
    }

    public function testToStringWithMixedTypes(): void
    {
        $array = new PDFArray;
        $array->add(new PDFNumber(1));
        $array->add(new PDFString('test'));
        $array->add(new PDFReference(3, 0));

        $result = (string) $array;
        $this->assertStringStartsWith('[', $result);
        $this->assertStringEndsWith(']', $result);
        $this->assertStringContainsString('1', $result);
        $this->assertStringContainsString('test', $result);
        $this->assertStringContainsString('3 0 R', $result);
    }
}
