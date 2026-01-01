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

namespace Test\Unit\PDF\Fpdf\ValueObject;

use InvalidArgumentException;
use Test\TestCase;
use PXP\PDF\Fpdf\ValueObject\PageSize;

/**
 * @covers \PXP\PDF\Fpdf\ValueObject\PageSize
 */
final class PageSizeTest extends TestCase
{
    private const SCALE_FACTOR = 72.0 / 25.4;

    public function testPageSizeIsReadonly(): void
    {
        $reflection = new \ReflectionClass(PageSize::class);
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testConstructorWithValidDimensions(): void
    {
        $pageSize = new PageSize(100.0, 200.0);
        $this->assertSame(100.0, $pageSize->getWidth());
        $this->assertSame(200.0, $pageSize->getHeight());
    }

    public function testConstructorThrowsExceptionForZeroWidth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Page dimensions must be positive');
        new PageSize(0.0, 200.0);
    }

    public function testConstructorThrowsExceptionForZeroHeight(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Page dimensions must be positive');
        new PageSize(100.0, 0.0);
    }

    public function testConstructorThrowsExceptionForNegativeWidth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Page dimensions must be positive');
        new PageSize(-100.0, 200.0);
    }

    public function testConstructorThrowsExceptionForNegativeHeight(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Page dimensions must be positive');
        new PageSize(100.0, -200.0);
    }

    public function testFromStringWithA3(): void
    {
        $pageSize = PageSize::fromString('a3', self::SCALE_FACTOR);
        $this->assertSame(841.89 / self::SCALE_FACTOR, $pageSize->getWidth());
        $this->assertSame(1190.55 / self::SCALE_FACTOR, $pageSize->getHeight());
    }

    public function testFromStringWithA4(): void
    {
        $pageSize = PageSize::fromString('a4', self::SCALE_FACTOR);
        $this->assertSame(595.28 / self::SCALE_FACTOR, $pageSize->getWidth());
        $this->assertSame(841.89 / self::SCALE_FACTOR, $pageSize->getHeight());
    }

    public function testFromStringWithA5(): void
    {
        $pageSize = PageSize::fromString('a5', self::SCALE_FACTOR);
        $this->assertSame(420.94 / self::SCALE_FACTOR, $pageSize->getWidth());
        $this->assertSame(595.28 / self::SCALE_FACTOR, $pageSize->getHeight());
    }

    public function testFromStringWithLetter(): void
    {
        $pageSize = PageSize::fromString('letter', self::SCALE_FACTOR);
        $this->assertSame(612.0 / self::SCALE_FACTOR, $pageSize->getWidth());
        $this->assertSame(792.0 / self::SCALE_FACTOR, $pageSize->getHeight());
    }

    public function testFromStringWithLegal(): void
    {
        $pageSize = PageSize::fromString('legal', self::SCALE_FACTOR);
        $this->assertSame(612.0 / self::SCALE_FACTOR, $pageSize->getWidth());
        $this->assertSame(1008.0 / self::SCALE_FACTOR, $pageSize->getHeight());
    }

    public function testFromStringCaseInsensitive(): void
    {
        $pageSize1 = PageSize::fromString('A4', self::SCALE_FACTOR);
        $pageSize2 = PageSize::fromString('a4', self::SCALE_FACTOR);
        $this->assertTrue($pageSize1->equals($pageSize2));
    }

    public function testFromStringThrowsExceptionForUnknownSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown page size: invalid');
        PageSize::fromString('invalid', self::SCALE_FACTOR);
    }

    public function testFromArrayWithWidthLessThanHeight(): void
    {
        $pageSize = PageSize::fromArray([100.0, 200.0], self::SCALE_FACTOR);
        $this->assertSame(100.0 / self::SCALE_FACTOR, $pageSize->getWidth());
        $this->assertSame(200.0 / self::SCALE_FACTOR, $pageSize->getHeight());
    }

    public function testFromArrayWithWidthGreaterThanHeight(): void
    {
        $pageSize = PageSize::fromArray([200.0, 100.0], self::SCALE_FACTOR);

        $this->assertSame(100.0 / self::SCALE_FACTOR, $pageSize->getWidth());
        $this->assertSame(200.0 / self::SCALE_FACTOR, $pageSize->getHeight());
    }

    public function testGetWidthInPoints(): void
    {
        $pageSize = new PageSize(100.0, 200.0);
        $scaleFactor = 2.0;
        $this->assertSame(200.0, $pageSize->getWidthInPoints($scaleFactor));
    }

    public function testGetHeightInPoints(): void
    {
        $pageSize = new PageSize(100.0, 200.0);
        $scaleFactor = 2.0;
        $this->assertSame(400.0, $pageSize->getHeightInPoints($scaleFactor));
    }

    public function testEqualsWithSameDimensions(): void
    {
        $pageSize1 = new PageSize(100.0, 200.0);
        $pageSize2 = new PageSize(100.0, 200.0);
        $this->assertTrue($pageSize1->equals($pageSize2));
    }

    public function testEqualsWithDifferentDimensions(): void
    {
        $pageSize1 = new PageSize(100.0, 200.0);
        $pageSize2 = new PageSize(200.0, 100.0);
        $this->assertFalse($pageSize1->equals($pageSize2));
    }

    public function testEqualsWithDifferentWidth(): void
    {
        $pageSize1 = new PageSize(100.0, 200.0);
        $pageSize2 = new PageSize(150.0, 200.0);
        $this->assertFalse($pageSize1->equals($pageSize2));
    }

    public function testEqualsWithDifferentHeight(): void
    {
        $pageSize1 = new PageSize(100.0, 200.0);
        $pageSize2 = new PageSize(100.0, 250.0);
        $this->assertFalse($pageSize1->equals($pageSize2));
    }
}
