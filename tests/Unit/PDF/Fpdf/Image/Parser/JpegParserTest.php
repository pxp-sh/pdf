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

use function file_exists;
use function file_put_contents;
use function sys_get_temp_dir;
use function uniqid;
use PXP\PDF\Fpdf\Exceptions\Exception\FpdfException;
use PXP\PDF\Fpdf\Rendering\Image\Parser\JpegParser;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Image\Parser\JpegParser
 */
final class JpegParserTest extends TestCase
{
    private JpegParser $jpegParser;

    protected function setUp(): void
    {
        $this->jpegParser = new JpegParser(self::createFileIO());
    }

    public function testSupportsJpg(): void
    {
        $this->assertTrue($this->jpegParser->supports('jpg'));
        $this->assertTrue($this->jpegParser->supports('JPG'));
        $this->assertTrue($this->jpegParser->supports('JpG'));
    }

    public function testSupportsJpeg(): void
    {
        $this->assertTrue($this->jpegParser->supports('jpeg'));
        $this->assertTrue($this->jpegParser->supports('JPEG'));
    }

    public function testSupportsReturnsFalseForOtherTypes(): void
    {
        $this->assertFalse($this->jpegParser->supports('png'));
        $this->assertFalse($this->jpegParser->supports('gif'));
        $this->assertFalse($this->jpegParser->supports('bmp'));
    }

    public function testParseThrowsExceptionForNonExistentFile(): void
    {
        $this->expectException(FpdfException::class);
        $this->expectExceptionMessage('Missing or incorrect image file:');
        $this->jpegParser->parse('/nonexistent/file.jpg');
    }

    public function testParseThrowsExceptionForNonJpegFile(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_' . uniqid() . '.jpg';
        file_put_contents($tempFile, 'not a jpeg');

        try {
            $this->expectException(FpdfException::class);
            $this->expectExceptionMessage('Missing or incorrect image file:');
            $this->jpegParser->parse($tempFile);
        } finally {
            if (file_exists($tempFile)) {
                self::unlink($tempFile);
            }
        }
    }
}
