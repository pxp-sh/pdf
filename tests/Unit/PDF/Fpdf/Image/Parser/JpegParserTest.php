<?php

declare(strict_types=1);

/**
 * Copyright (c) 2025 PXP
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/pxp-sh/pdf
 *
 */

namespace Test\Unit\PDF\Fpdf\Image\Parser;

use PHPUnit\Framework\TestCase;
use PXP\PDF\Fpdf\Exception\FpdfException;
use PXP\PDF\Fpdf\Image\Parser\JpegParser;

/**
 * @covers \PXP\PDF\Fpdf\Image\Parser\JpegParser
 */
final class JpegParserTest extends TestCase
{
    private JpegParser $parser;

    protected function setUp(): void
    {
        $this->parser = new JpegParser();
    }

    public function testSupportsJpg(): void
    {
        $this->assertTrue($this->parser->supports('jpg'));
        $this->assertTrue($this->parser->supports('JPG'));
        $this->assertTrue($this->parser->supports('JpG'));
    }

    public function testSupportsJpeg(): void
    {
        $this->assertTrue($this->parser->supports('jpeg'));
        $this->assertTrue($this->parser->supports('JPEG'));
    }

    public function testSupportsReturnsFalseForOtherTypes(): void
    {
        $this->assertFalse($this->parser->supports('png'));
        $this->assertFalse($this->parser->supports('gif'));
        $this->assertFalse($this->parser->supports('bmp'));
    }

    public function testParseThrowsExceptionForNonExistentFile(): void
    {
        $this->expectException(FpdfException::class);
        $this->expectExceptionMessage('Missing or incorrect image file:');
        $this->parser->parse('/nonexistent/file.jpg');
    }

    public function testParseThrowsExceptionForNonJpegFile(): void
    {
        // Create a temporary file that's not a JPEG
        // getimagesize will return false for invalid image, which triggers "Missing or incorrect" error first
        $tempFile = sys_get_temp_dir() . '/test_' . uniqid() . '.jpg';
        file_put_contents($tempFile, 'not a jpeg');

        try {
            $this->expectException(FpdfException::class);
            $this->expectExceptionMessage('Missing or incorrect image file:');
            $this->parser->parse($tempFile);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
}
