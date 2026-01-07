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
namespace Test\Unit\PDF\Fpdf\Image\Parser;

use function function_exists;
use PXP\PDF\Fpdf\Exceptions\Exception\FpdfException;
use PXP\PDF\Fpdf\Rendering\Image\Parser\GifParser;
use PXP\PDF\Fpdf\Rendering\Image\Parser\PngParser;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Image\Parser\GifParser
 */
final class GifParserTest extends TestCase
{
    private GifParser $parser;

    protected function setUp(): void
    {
        $fileIO       = self::createFileIO();
        $pngParser    = new PngParser($fileIO, $fileIO);
        $this->parser = new GifParser($pngParser, $fileIO);
    }

    public function testSupportsGif(): void
    {
        $this->assertTrue($this->parser->supports('gif'));
        $this->assertTrue($this->parser->supports('GIF'));
        $this->assertTrue($this->parser->supports('GiF'));
    }

    public function testSupportsReturnsFalseForOtherTypes(): void
    {
        $this->assertFalse($this->parser->supports('jpg'));
        $this->assertFalse($this->parser->supports('png'));
        $this->assertFalse($this->parser->supports('bmp'));
    }

    public function testParseThrowsExceptionWhenGdExtensionNotAvailable(): void
    {
        if (function_exists('imagepng')) {
            $this->markTestSkipped('GD extension is available');
        }

        $this->expectException(FpdfException::class);
        $this->expectExceptionMessage('GD extension is required for GIF support');
        $this->parser->parse('/nonexistent/file.gif');
    }

    public function testParseThrowsExceptionWhenGdHasNoGifSupport(): void
    {
        if (!function_exists('imagepng')) {
            $this->markTestSkipped('GD extension is not available');
        }

        if (function_exists('imagecreatefromgif')) {
            $this->markTestSkipped('GD has GIF support');
        }

        $this->expectException(FpdfException::class);
        $this->expectExceptionMessage('GD has no GIF read support');
        $this->parser->parse('/nonexistent/file.gif');
    }

    public function testParseThrowsExceptionForNonExistentFile(): void
    {
        if (!function_exists('imagecreatefromgif')) {
            $this->markTestSkipped('GD extension with GIF support is not available');
        }

        $this->expectException(FpdfException::class);
        $this->expectExceptionMessage('Missing or incorrect image file:');
        $this->parser->parse('/nonexistent/file.gif');
    }
}
