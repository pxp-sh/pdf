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
use function unlink;
use PXP\PDF\Fpdf\Exception\FpdfException;
use PXP\PDF\Fpdf\Image\Parser\PngParser;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Image\Parser\PngParser
 */
final class PngParserTest extends TestCase
{
    private PngParser $parser;

    protected function setUp(): void
    {
        $fileIO       = self::createFileIO();
        $this->parser = new PngParser($fileIO, $fileIO);
    }

    public function testSupportsPng(): void
    {
        $this->assertTrue($this->parser->supports('png'));
        $this->assertTrue($this->parser->supports('PNG'));
        $this->assertTrue($this->parser->supports('PnG'));
    }

    public function testSupportsReturnsFalseForOtherTypes(): void
    {
        $this->assertFalse($this->parser->supports('jpg'));
        $this->assertFalse($this->parser->supports('gif'));
        $this->assertFalse($this->parser->supports('bmp'));
    }

    public function testParseThrowsExceptionForNonExistentFile(): void
    {
        $this->expectException(FpdfException::class);
        $this->expectExceptionMessage('File not found or not readable:');
        $this->parser->parse('/nonexistent/file.png');
    }

    public function testParseThrowsExceptionForInvalidPngFile(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_' . uniqid() . '.png';
        file_put_contents($tempFile, 'not a png');

        try {
            $this->expectException(FpdfException::class);
            $this->expectExceptionMessage('Not a PNG file:');
            $this->parser->parse($tempFile);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
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
            $this->parser->parseStream($stream, 'test.png');
        } finally {
            fclose($stream);
        }
    }
}
