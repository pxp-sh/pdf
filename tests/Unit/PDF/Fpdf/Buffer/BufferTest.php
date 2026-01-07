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
namespace Test\Unit\PDF\Fpdf\Buffer;

use PXP\PDF\Fpdf\Utils\Buffer\Buffer;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Buffer\Buffer
 */
final class BufferTest extends TestCase
{
    public function testAppendAddsLineWithNewline(): void
    {
        $buffer = new Buffer;
        $buffer->append('test line');

        $content = $buffer->getContent();
        $this->assertSame("test line\n", $content);
    }

    public function testAppendMultipleLines(): void
    {
        $buffer = new Buffer;
        $buffer->append('line 1');
        $buffer->append('line 2');
        $buffer->append('line 3');

        $content = $buffer->getContent();
        $this->assertSame("line 1\nline 2\nline 3\n", $content);
    }

    public function testGetContentReturnsEmptyStringInitially(): void
    {
        $buffer = new Buffer;
        $this->assertSame('', $buffer->getContent());
    }

    public function testGetLengthReturnsZeroInitially(): void
    {
        $buffer = new Buffer;
        $this->assertSame(0, $buffer->getLength());
    }

    public function testGetLengthReturnsCorrectLength(): void
    {
        $buffer = new Buffer;
        $buffer->append('test');
        $this->assertSame(5, $buffer->getLength());
    }

    public function testGetLengthWithMultipleLines(): void
    {
        $buffer = new Buffer;
        $buffer->append('line 1');
        $buffer->append('line 2');
        $this->assertSame(14, $buffer->getLength());
    }

    public function testClearResetsContent(): void
    {
        $buffer = new Buffer;
        $buffer->append('test line');
        $buffer->clear();

        $this->assertSame('', $buffer->getContent());
        $this->assertSame(0, $buffer->getLength());
    }

    public function testClearAfterMultipleAppends(): void
    {
        $buffer = new Buffer;
        $buffer->append('line 1');
        $buffer->append('line 2');
        $buffer->append('line 3');
        $buffer->clear();

        $this->assertSame('', $buffer->getContent());
        $this->assertSame(0, $buffer->getLength());
    }

    public function testAppendAfterClear(): void
    {
        $buffer = new Buffer;
        $buffer->append('old line');
        $buffer->clear();
        $buffer->append('new line');

        $this->assertSame("new line\n", $buffer->getContent());
    }

    public function testAppendEmptyString(): void
    {
        $buffer = new Buffer;
        $buffer->append('');

        $this->assertSame("\n", $buffer->getContent());
        $this->assertSame(1, $buffer->getLength());
    }

    public function testAppendSpecialCharacters(): void
    {
        $buffer = new Buffer;
        $buffer->append('test with "quotes" and \'apostrophes\'');

        $content = $buffer->getContent();
        $this->assertStringContainsString('test with "quotes"', $content);
        $this->assertStringEndsWith("\n", $content);
    }
}
