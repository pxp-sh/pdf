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
use PXP\PDF\Fpdf\Enum\OutputDestination;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Enum\OutputDestination
 */
final class OutputDestinationTest extends TestCase
{
    public function testInlineCase(): void
    {
        $this->assertSame('I', OutputDestination::INLINE->value);
    }

    public function testDownloadCase(): void
    {
        $this->assertSame('D', OutputDestination::DOWNLOAD->value);
    }

    public function testFileCase(): void
    {
        $this->assertSame('F', OutputDestination::FILE->value);
    }

    public function testStringCase(): void
    {
        $this->assertSame('S', OutputDestination::STRING->value);
    }

    public function testFromStringWithInline(): void
    {
        $this->assertSame(OutputDestination::INLINE, OutputDestination::fromString('I'));
        $this->assertSame(OutputDestination::INLINE, OutputDestination::fromString('i'));
    }

    public function testFromStringWithDownload(): void
    {
        $this->assertSame(OutputDestination::DOWNLOAD, OutputDestination::fromString('D'));
        $this->assertSame(OutputDestination::DOWNLOAD, OutputDestination::fromString('d'));
    }

    public function testFromStringWithFile(): void
    {
        $this->assertSame(OutputDestination::FILE, OutputDestination::fromString('F'));
        $this->assertSame(OutputDestination::FILE, OutputDestination::fromString('f'));
    }

    public function testFromStringWithString(): void
    {
        $this->assertSame(OutputDestination::STRING, OutputDestination::fromString('S'));
        $this->assertSame(OutputDestination::STRING, OutputDestination::fromString('s'));
    }

    public function testFromStringThrowsExceptionForInvalidValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Incorrect output destination: X');
        OutputDestination::fromString('X');
    }
}
