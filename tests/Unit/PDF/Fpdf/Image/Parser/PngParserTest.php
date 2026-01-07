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

use function fclose;
use function file_exists;
use function file_put_contents;
use function fopen;
use function fwrite;
use function rewind;
use function sys_get_temp_dir;
use function uniqid;
use PXP\PDF\Fpdf\Exceptions\Exception\FpdfException;
use PXP\PDF\Fpdf\Rendering\Image\Parser\PngParser;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Image\Parser\PngParser
 */
final class PngParserTest extends TestCase
{
    private PngParser $pngParser;

    protected function setUp(): void
    {
        $fileIO          = self::createFileIO();
        $this->pngParser = new PngParser($fileIO, $fileIO);
    }

    public function testSupportsPng(): void
    {
        $this->assertTrue($this->pngParser->supports('png'));
        $this->assertTrue($this->pngParser->supports('PNG'));
        $this->assertTrue($this->pngParser->supports('PnG'));
    }

    public function testSupportsReturnsFalseForOtherTypes(): void
    {
        $this->assertFalse($this->pngParser->supports('jpg'));
        $this->assertFalse($this->pngParser->supports('gif'));
        $this->assertFalse($this->pngParser->supports('bmp'));
    }

    public function testParseThrowsExceptionForNonExistentFile(): void
    {
        $this->expectException(FpdfException::class);
        $this->expectExceptionMessage('File not found or not readable:');
        $this->pngParser->parse('/nonexistent/file.png');
    }

    public function testParseThrowsExceptionForInvalidPngFile(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_' . uniqid() . '.png';
        file_put_contents($tempFile, 'not a png');

        try {
            $this->expectException(FpdfException::class);
            $this->expectExceptionMessage('Not a PNG file:');
            $this->pngParser->parse($tempFile);
        } finally {
            if (file_exists($tempFile)) {
                self::unlink($tempFile);
            }
        }
    }

    public function testParseStreamThrowsExceptionForInvalidSignature(): void
    {
        $stream = fopen('php://temp', 'rb+');

        if ($stream === false) {
            $this->markTestSkipped('Could not create stream');
        }

        fwrite($stream, 'invalid signature');
        rewind($stream);

        try {
            $this->expectException(FpdfException::class);
            $this->expectExceptionMessage('Not a PNG file:');
            $this->pngParser->parseStream($stream, 'test.png');
        } finally {
            fclose($stream);
        }
    }
}
