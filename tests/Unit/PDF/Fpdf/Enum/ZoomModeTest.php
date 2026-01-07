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
use PXP\PDF\Fpdf\Utils\Enum\ZoomMode;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Enum\ZoomMode
 */
final class ZoomModeTest extends TestCase
{
    public function testFullpageCase(): void
    {
        $this->assertSame('fullpage', ZoomMode::FULLPAGE->value);
    }

    public function testFullwidthCase(): void
    {
        $this->assertSame('fullwidth', ZoomMode::FULLWIDTH->value);
    }

    public function testRealCase(): void
    {
        $this->assertSame('real', ZoomMode::REAL->value);
    }

    public function testDefaultCase(): void
    {
        $this->assertSame('default', ZoomMode::DEFAULT->value);
    }

    public function testFromValueWithFloat(): void
    {
        $result = ZoomMode::fromValue(150.0);
        $this->assertSame(150.0, $result);
        $this->assertIsFloat($result);
    }

    public function testFromValueWithFullpage(): void
    {
        $result = ZoomMode::fromValue('fullpage');
        $this->assertSame('fullpage', $result);
    }

    public function testFromValueWithFullwidth(): void
    {
        $result = ZoomMode::fromValue('fullwidth');
        $this->assertSame('fullwidth', $result);
    }

    public function testFromValueWithReal(): void
    {
        $result = ZoomMode::fromValue('real');
        $this->assertSame('real', $result);
    }

    public function testFromValueWithDefault(): void
    {
        $result = ZoomMode::fromValue('default');
        $this->assertSame('default', $result);
    }

    public function testFromValueCaseInsensitive(): void
    {
        $this->assertSame('fullpage', ZoomMode::fromValue('FULLPAGE'));
        $this->assertSame('fullwidth', ZoomMode::fromValue('FullWidth'));
        $this->assertSame('real', ZoomMode::fromValue('REAL'));
        $this->assertSame('default', ZoomMode::fromValue('DEFAULT'));
    }

    public function testFromValueThrowsExceptionForInvalidString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Incorrect zoom display mode: invalid');
        ZoomMode::fromValue('invalid');
    }
}
