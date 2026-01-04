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
namespace Test\Unit\PDF\Fpdf\Enum;

use InvalidArgumentException;
use PXP\PDF\Fpdf\Enum\Unit;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Enum\Unit
 */
final class UnitTest extends TestCase
{
    public function testPointCase(): void
    {
        $this->assertSame('pt', Unit::POINT->value);
    }

    public function testMillimeterCase(): void
    {
        $this->assertSame('mm', Unit::MILLIMETER->value);
    }

    public function testCentimeterCase(): void
    {
        $this->assertSame('cm', Unit::CENTIMETER->value);
    }

    public function testInchCase(): void
    {
        $this->assertSame('in', Unit::INCH->value);
    }

    public function testFromStringWithPoint(): void
    {
        $this->assertSame(Unit::POINT, Unit::fromString('pt'));
        $this->assertSame(Unit::POINT, Unit::fromString('PT'));
        $this->assertSame(Unit::POINT, Unit::fromString('point'));
        $this->assertSame(Unit::POINT, Unit::fromString('POINT'));
        $this->assertSame(Unit::POINT, Unit::fromString('Point'));
    }

    public function testFromStringWithMillimeter(): void
    {
        $this->assertSame(Unit::MILLIMETER, Unit::fromString('mm'));
        $this->assertSame(Unit::MILLIMETER, Unit::fromString('MM'));
        $this->assertSame(Unit::MILLIMETER, Unit::fromString('millimeter'));
        $this->assertSame(Unit::MILLIMETER, Unit::fromString('MILLIMETER'));
    }

    public function testFromStringWithCentimeter(): void
    {
        $this->assertSame(Unit::CENTIMETER, Unit::fromString('cm'));
        $this->assertSame(Unit::CENTIMETER, Unit::fromString('CM'));
        $this->assertSame(Unit::CENTIMETER, Unit::fromString('centimeter'));
        $this->assertSame(Unit::CENTIMETER, Unit::fromString('CENTIMETER'));
    }

    public function testFromStringWithInch(): void
    {
        $this->assertSame(Unit::INCH, Unit::fromString('in'));
        $this->assertSame(Unit::INCH, Unit::fromString('IN'));
        $this->assertSame(Unit::INCH, Unit::fromString('inch'));
        $this->assertSame(Unit::INCH, Unit::fromString('INCH'));
    }

    public function testFromStringThrowsExceptionForInvalidValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Incorrect unit: invalid');
        Unit::fromString('invalid');
    }

    public function testGetScaleFactorForPoint(): void
    {
        $this->assertSame(1.0, Unit::POINT->getScaleFactor());
    }

    public function testGetScaleFactorForMillimeter(): void
    {
        $this->assertSame(72.0 / 25.4, Unit::MILLIMETER->getScaleFactor());
    }

    public function testGetScaleFactorForCentimeter(): void
    {
        $this->assertSame(72.0 / 2.54, Unit::CENTIMETER->getScaleFactor());
    }

    public function testGetScaleFactorForInch(): void
    {
        $this->assertSame(72.0, Unit::INCH->getScaleFactor());
    }
}
