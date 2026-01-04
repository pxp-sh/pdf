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
namespace Test\Unit\PDF\Fpdf\Charset;

use function iconv;
use PXP\PDF\Fpdf\Charset\CharsetHandler;
use PXP\PDF\Fpdf\Exception\FpdfException;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Charset\CharsetHandler
 */
final class CharsetHandlerTest extends TestCase
{
    private CharsetHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new CharsetHandler;
    }

    public function testEncodeToPDFWithUTF8(): void
    {
        $text    = 'test';
        $encoded = $this->handler->encodeToPDF($text, 'UTF-8');
        $this->assertIsString($encoded);
    }

    public function testEncodeToPDFWithUTF16BE(): void
    {
        $text    = 'test';
        $encoded = $this->handler->encodeToPDF($text, 'UTF-16BE');
        $this->assertStringStartsWith("\xFE\xFF", $encoded);
    }

    public function testDecodeFromPDFWithUTF16BE(): void
    {
        $encoded = "\xFE\xFF" . iconv('UTF-8', 'UTF-16BE', 'test');
        $decoded = $this->handler->decodeFromPDF($encoded, 'UTF-16BE');
        $this->assertSame('test', $decoded);
    }

    public function testDetectCharsetWithUTF16BOM(): void
    {
        $text    = "\xFE\xFFtest";
        $charset = $this->handler->detectCharset($text);
        $this->assertSame('UTF-16BE', $charset);
    }

    public function testDetectCharsetWithUTF8(): void
    {
        $text    = 'test';
        $charset = $this->handler->detectCharset($text);
        $this->assertSame('UTF-8', $charset);
    }

    public function testConvertCharset(): void
    {
        $text      = 'test';
        $converted = $this->handler->convertCharset($text, 'UTF-8', 'UTF-8');
        $this->assertSame($text, $converted);
    }

    public function testEncodeToPDFThrowsExceptionForUnsupportedCharset(): void
    {
        $this->expectException(FpdfException::class);
        $this->handler->encodeToPDF('test', 'UnsupportedCharset');
    }

    public function testDecodeFromPDFThrowsExceptionForUnsupportedCharset(): void
    {
        $this->expectException(FpdfException::class);
        $this->handler->decodeFromPDF('test', 'UnsupportedCharset');
    }
}
