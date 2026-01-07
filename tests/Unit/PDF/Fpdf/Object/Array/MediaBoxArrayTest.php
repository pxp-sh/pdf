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
        $mediaBoxArray = new MediaBoxArray;
        $values        = $mediaBoxArray->getValues();

        $this->assertSame([0.0, 0.0, 612.0, 792.0], $values);
    }

    public function testConstructorWithCustomValues(): void
    {
        $mediaBoxArray = new MediaBoxArray([10.0, 20.0, 800.0, 1000.0]);
        $values        = $mediaBoxArray->getValues();

        $this->assertSame([10.0, 20.0, 800.0, 1000.0], $values);
    }

    public function testSetValues(): void
    {
        $mediaBoxArray = new MediaBoxArray;
        $mediaBoxArray->setValues([5.0, 10.0, 500.0, 600.0]);

        $values = $mediaBoxArray->getValues();
        $this->assertSame([5.0, 10.0, 500.0, 600.0], $values);
    }

    public function testGetLLX(): void
    {
        $mediaBoxArray = new MediaBoxArray([10.0, 20.0, 800.0, 1000.0]);
        $this->assertSame(10.0, $mediaBoxArray->getLLX());
    }

    public function testGetLLY(): void
    {
        $mediaBoxArray = new MediaBoxArray([10.0, 20.0, 800.0, 1000.0]);
        $this->assertSame(20.0, $mediaBoxArray->getLLY());
    }

    public function testGetURX(): void
    {
        $mediaBoxArray = new MediaBoxArray([10.0, 20.0, 800.0, 1000.0]);
        $this->assertSame(800.0, $mediaBoxArray->getURX());
    }

    public function testGetURY(): void
    {
        $mediaBoxArray = new MediaBoxArray([10.0, 20.0, 800.0, 1000.0]);
        $this->assertSame(1000.0, $mediaBoxArray->getURY());
    }

    public function testGetWidth(): void
    {
        $mediaBoxArray = new MediaBoxArray([10.0, 20.0, 800.0, 1000.0]);
        $this->assertSame(790.0, $mediaBoxArray->getWidth());
    }

    public function testGetHeight(): void
    {
        $mediaBoxArray = new MediaBoxArray([10.0, 20.0, 800.0, 1000.0]);
        $this->assertSame(980.0, $mediaBoxArray->getHeight());
    }
}
