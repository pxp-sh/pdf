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
use PXP\PDF\Fpdf\Utils\Enum\PageOrientation;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Enum\PageOrientation
 */
final class PageOrientationTest extends TestCase
{
    public function testPortraitCase(): void
    {
        $this->assertSame('P', PageOrientation::PORTRAIT->value);
    }

    public function testLandscapeCase(): void
    {
        $this->assertSame('L', PageOrientation::LANDSCAPE->value);
    }

    public function testFromStringWithPortrait(): void
    {
        $this->assertSame(PageOrientation::PORTRAIT, PageOrientation::fromString('P'));
        $this->assertSame(PageOrientation::PORTRAIT, PageOrientation::fromString('p'));
        $this->assertSame(PageOrientation::PORTRAIT, PageOrientation::fromString('portrait'));
        $this->assertSame(PageOrientation::PORTRAIT, PageOrientation::fromString('PORTRAIT'));
        $this->assertSame(PageOrientation::PORTRAIT, PageOrientation::fromString('Portrait'));
    }

    public function testFromStringWithLandscape(): void
    {
        $this->assertSame(PageOrientation::LANDSCAPE, PageOrientation::fromString('L'));
        $this->assertSame(PageOrientation::LANDSCAPE, PageOrientation::fromString('l'));
        $this->assertSame(PageOrientation::LANDSCAPE, PageOrientation::fromString('landscape'));
        $this->assertSame(PageOrientation::LANDSCAPE, PageOrientation::fromString('LANDSCAPE'));
        $this->assertSame(PageOrientation::LANDSCAPE, PageOrientation::fromString('Landscape'));
    }

    public function testFromStringThrowsExceptionForInvalidValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Incorrect orientation: invalid');
        PageOrientation::fromString('invalid');
    }
}
