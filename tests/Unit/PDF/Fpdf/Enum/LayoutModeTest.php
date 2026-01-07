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
use PXP\PDF\Fpdf\Utils\Enum\LayoutMode;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Enum\LayoutMode
 */
final class LayoutModeTest extends TestCase
{
    public function testSingleCase(): void
    {
        $this->assertSame('single', LayoutMode::SINGLE->value);
    }

    public function testContinuousCase(): void
    {
        $this->assertSame('continuous', LayoutMode::CONTINUOUS->value);
    }

    public function testTwoCase(): void
    {
        $this->assertSame('two', LayoutMode::TWO->value);
    }

    public function testDefaultCase(): void
    {
        $this->assertSame('default', LayoutMode::DEFAULT->value);
    }

    public function testFromStringWithSingle(): void
    {
        $this->assertSame(LayoutMode::SINGLE, LayoutMode::fromString('single'));
        $this->assertSame(LayoutMode::SINGLE, LayoutMode::fromString('SINGLE'));
        $this->assertSame(LayoutMode::SINGLE, LayoutMode::fromString('Single'));
    }

    public function testFromStringWithContinuous(): void
    {
        $this->assertSame(LayoutMode::CONTINUOUS, LayoutMode::fromString('continuous'));
        $this->assertSame(LayoutMode::CONTINUOUS, LayoutMode::fromString('CONTINUOUS'));
        $this->assertSame(LayoutMode::CONTINUOUS, LayoutMode::fromString('Continuous'));
    }

    public function testFromStringWithTwo(): void
    {
        $this->assertSame(LayoutMode::TWO, LayoutMode::fromString('two'));
        $this->assertSame(LayoutMode::TWO, LayoutMode::fromString('TWO'));
        $this->assertSame(LayoutMode::TWO, LayoutMode::fromString('Two'));
    }

    public function testFromStringWithDefault(): void
    {
        $this->assertSame(LayoutMode::DEFAULT, LayoutMode::fromString('default'));
        $this->assertSame(LayoutMode::DEFAULT, LayoutMode::fromString('DEFAULT'));
        $this->assertSame(LayoutMode::DEFAULT, LayoutMode::fromString('Default'));
    }

    public function testFromStringThrowsExceptionForInvalidValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Incorrect layout display mode: invalid');
        LayoutMode::fromString('invalid');
    }
}
