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
namespace Test\Unit\PDF\Fpdf\Color;

use PXP\PDF\Fpdf\Color\ColorManager;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Color\ColorManager
 */
final class ColorManagerTest extends TestCase
{
    private ColorManager $colorManager;

    protected function setUp(): void
    {
        $this->colorManager = new ColorManager;
    }

    public function testInitialState(): void
    {
        $this->assertSame('0 G', $this->colorManager->getDrawColor());
        $this->assertSame('0 g', $this->colorManager->getFillColor());
        $this->assertSame('0 g', $this->colorManager->getTextColor());
        $this->assertFalse($this->colorManager->hasColorFlag());
    }

    public function testSetDrawColorGrayscale(): void
    {
        $result = $this->colorManager->setDrawColor(128);
        $this->assertSame('0.502 G', $result);
        $this->assertSame('0.502 G', $this->colorManager->getDrawColor());
    }

    public function testSetDrawColorGrayscaleBlack(): void
    {
        $result = $this->colorManager->setDrawColor(0);
        $this->assertSame('0.000 G', $result);
    }

    public function testSetDrawColorGrayscaleWhite(): void
    {
        $result = $this->colorManager->setDrawColor(255);
        $this->assertSame('1.000 G', $result);
    }

    public function testSetDrawColorRgb(): void
    {
        $result = $this->colorManager->setDrawColor(255, 128, 64);
        $this->assertSame('1.000 0.502 0.251 RG', $result);
        $this->assertSame('1.000 0.502 0.251 RG', $this->colorManager->getDrawColor());
    }

    public function testSetDrawColorRgbBlack(): void
    {
        $result = $this->colorManager->setDrawColor(0, 0, 0);
        $this->assertSame('0.000 G', $result);
    }

    public function testSetFillColorGrayscale(): void
    {
        $result = $this->colorManager->setFillColor(128);
        $this->assertSame('0.502 g', $result);
        $this->assertSame('0.502 g', $this->colorManager->getFillColor());
        $this->assertTrue($this->colorManager->hasColorFlag());
    }

    public function testSetFillColorRgb(): void
    {
        $result = $this->colorManager->setFillColor(255, 128, 64);
        $this->assertSame('1.000 0.502 0.251 rg', $result);
        $this->assertSame('1.000 0.502 0.251 rg', $this->colorManager->getFillColor());
    }

    public function testSetFillColorRgbBlack(): void
    {
        $result = $this->colorManager->setFillColor(0, 0, 0);
        $this->assertSame('0.000 g', $result);
    }

    public function testSetTextColorGrayscale(): void
    {
        $this->colorManager->setTextColor(128);
        $this->assertSame('0.502 g', $this->colorManager->getTextColor());
    }

    public function testSetTextColorRgb(): void
    {
        $this->colorManager->setTextColor(255, 128, 64);
        $this->assertSame('1.000 0.502 0.251 rg', $this->colorManager->getTextColor());
    }

    public function testColorFlagWhenFillAndTextMatch(): void
    {
        $this->colorManager->setFillColor(128);
        $this->colorManager->setTextColor(128);
        $this->assertFalse($this->colorManager->hasColorFlag());
    }

    public function testColorFlagWhenFillAndTextDiffer(): void
    {
        $this->colorManager->setFillColor(128);
        $this->colorManager->setTextColor(200);
        $this->assertTrue($this->colorManager->hasColorFlag());
    }

    public function testColorFlagWithRgbColors(): void
    {
        $this->colorManager->setFillColor(255, 0, 0);
        $this->colorManager->setTextColor(0, 255, 0);
        $this->assertTrue($this->colorManager->hasColorFlag());
    }

    public function testReset(): void
    {
        $this->colorManager->setDrawColor(255, 128, 64);
        $this->colorManager->setFillColor(128);
        $this->colorManager->setTextColor(200);
        $this->colorManager->reset();

        $this->assertSame('0 G', $this->colorManager->getDrawColor());
        $this->assertSame('0 g', $this->colorManager->getFillColor());
        $this->assertSame('0 g', $this->colorManager->getTextColor());
        $this->assertFalse($this->colorManager->hasColorFlag());
    }

    public function testSetDrawColorWithNullG(): void
    {
        $result = $this->colorManager->setDrawColor(100, null);
        $this->assertSame('0.392 G', $result);
    }

    public function testSetFillColorWithNullG(): void
    {
        $result = $this->colorManager->setFillColor(100, null);
        $this->assertSame('0.392 g', $result);
    }

    public function testSetTextColorWithNullG(): void
    {
        $this->colorManager->setTextColor(100, null);
        $this->assertSame('0.392 g', $this->colorManager->getTextColor());
    }

    public function testColorPrecision(): void
    {
        $result = $this->colorManager->setDrawColor(1, 2, 3);
        $this->assertSame('0.004 0.008 0.012 RG', $result);
    }
}
