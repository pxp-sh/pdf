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
namespace Test\Unit\PDF\Fpdf\Text;

use PXP\PDF\Fpdf\Rendering\Text\TextRenderer;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Text\TextRenderer
 */
final class TextRendererTest extends TestCase
{
    private TextRenderer $textRenderer;

    protected function setUp(): void
    {
        $this->textRenderer = new TextRenderer;
    }

    public function testEscapeWithNoSpecialCharacters(): void
    {
        $result = $this->textRenderer->escape('simple text');
        $this->assertSame('simple text', $result);
    }

    public function testEscapeWithParentheses(): void
    {
        $result = $this->textRenderer->escape('text (with) parentheses');
        $this->assertSame('text \\(with\\) parentheses', $result);
    }

    public function testEscapeWithBackslash(): void
    {
        $result = $this->textRenderer->escape('text\\with\\backslashes');
        $this->assertSame('text\\\\with\\\\backslashes', $result);
    }

    public function testEscapeWithCarriageReturn(): void
    {
        $result = $this->textRenderer->escape("text\rwith\rreturns");
        $this->assertSame('text\\rwith\\rreturns', $result);
    }

    public function testEscapeWithAllSpecialCharacters(): void
    {
        $result = $this->textRenderer->escape("text (with) \\backslash\rreturn");
        $this->assertSame('text \\(with\\) \\\\backslash\\rreturn', $result);
    }

    public function testGetStringWidth(): void
    {
        $cw       = ['a' => 500, 'b' => 600, 'c' => 700];
        $fontSize = 12.0;
        $result   = $this->textRenderer->getStringWidth('abc', $cw, $fontSize);
        $this->assertSame((500 + 600 + 700) * 12.0 / 1000, $result);
    }

    public function testGetStringWidthWithUnknownCharacter(): void
    {
        $cw       = ['a' => 500];
        $fontSize = 12.0;
        $result   = $this->textRenderer->getStringWidth('ax', $cw, $fontSize);
        $this->assertSame((500) * 12.0 / 1000, $result);
    }

    public function testGetStringWidthWithEmptyString(): void
    {
        $cw       = ['a' => 500];
        $fontSize = 12.0;
        $result   = $this->textRenderer->getStringWidth('', $cw, $fontSize);
        $this->assertSame(0.0, $result);
    }

    public function testIsAsciiWithAsciiString(): void
    {
        $this->assertTrue($this->textRenderer->isAscii('ASCII text'));
    }

    public function testIsAsciiWithNonAsciiString(): void
    {
        $this->assertFalse($this->textRenderer->isAscii('Café'));
    }

    public function testIsAsciiWithEmptyString(): void
    {
        $this->assertTrue($this->textRenderer->isAscii(''));
    }

    public function testUtf8ToUtf16(): void
    {
        $result = $this->textRenderer->utf8ToUtf16('test');
        $this->assertStringStartsWith("\xFE\xFF", $result);
    }

    public function testTextStringWithAscii(): void
    {
        $result = $this->textRenderer->textString('test');
        $this->assertSame('(test)', $result);
    }

    public function testTextStringWithNonAscii(): void
    {
        $result = $this->textRenderer->textString('Café');
        $this->assertStringStartsWith('(', $result);
        $this->assertStringEndsWith(')', $result);
    }

    public function testTextStringEscapesSpecialCharacters(): void
    {
        $result = $this->textRenderer->textString('test (with) parentheses');
        $this->assertSame('(test \\(with\\) parentheses)', $result);
    }

    public function testHttpEncodeWithAscii(): void
    {
        $result = $this->textRenderer->httpEncode('filename', 'test.pdf', false);
        $this->assertSame('filename="test.pdf"', $result);
    }

    public function testHttpEncodeWithNonAsciiNotUtf8(): void
    {
        $result = $this->textRenderer->httpEncode('filename', 'Café', false);
        $this->assertStringStartsWith('filename*=UTF-8\'\'', $result);
    }

    public function testHttpEncodeWithNonAsciiUtf8(): void
    {
        $result = $this->textRenderer->httpEncode('filename', 'Café', true);
        $this->assertStringStartsWith('filename*=UTF-8\'\'', $result);
    }

    public function testUtf8Encode(): void
    {
        $result = $this->textRenderer->utf8Encode('test');
        $this->assertIsString($result);
    }
}
