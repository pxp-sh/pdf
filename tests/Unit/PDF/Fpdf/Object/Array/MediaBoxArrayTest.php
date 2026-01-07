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
namespace Test\Unit\PDF\Fpdf\Object\Array;

use PXP\PDF\Fpdf\Core\Object\Array\MediaBoxArray;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Object\Array\MediaBoxArray
 */
final class MediaBoxArrayTest extends TestCase
{
    public function testConstructorWithDefaultValues(): void
    {
        $mediaBox = new MediaBoxArray;
        $values   = $mediaBox->getValues();

        $this->assertSame([0.0, 0.0, 612.0, 792.0], $values);
    }

    public function testConstructorWithCustomValues(): void
    {
        $mediaBox = new MediaBoxArray([10.0, 20.0, 800.0, 1000.0]);
        $values   = $mediaBox->getValues();

        $this->assertSame([10.0, 20.0, 800.0, 1000.0], $values);
    }

    public function testSetValues(): void
    {
        $mediaBox = new MediaBoxArray;
        $mediaBox->setValues([5.0, 10.0, 500.0, 600.0]);

        $values = $mediaBox->getValues();
        $this->assertSame([5.0, 10.0, 500.0, 600.0], $values);
    }

    public function testGetLLX(): void
    {
        $mediaBox = new MediaBoxArray([10.0, 20.0, 800.0, 1000.0]);
        $this->assertSame(10.0, $mediaBox->getLLX());
    }

    public function testGetLLY(): void
    {
        $mediaBox = new MediaBoxArray([10.0, 20.0, 800.0, 1000.0]);
        $this->assertSame(20.0, $mediaBox->getLLY());
    }

    public function testGetURX(): void
    {
        $mediaBox = new MediaBoxArray([10.0, 20.0, 800.0, 1000.0]);
        $this->assertSame(800.0, $mediaBox->getURX());
    }

    public function testGetURY(): void
    {
        $mediaBox = new MediaBoxArray([10.0, 20.0, 800.0, 1000.0]);
        $this->assertSame(1000.0, $mediaBox->getURY());
    }

    public function testGetWidth(): void
    {
        $mediaBox = new MediaBoxArray([10.0, 20.0, 800.0, 1000.0]);
        $this->assertSame(790.0, $mediaBox->getWidth());
    }

    public function testGetHeight(): void
    {
        $mediaBox = new MediaBoxArray([10.0, 20.0, 800.0, 1000.0]);
        $this->assertSame(980.0, $mediaBox->getHeight());
    }
}
