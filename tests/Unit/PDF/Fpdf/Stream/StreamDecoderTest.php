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

namespace Test\Unit\PDF\Fpdf\Stream;

use Test\TestCase;
use PXP\PDF\Fpdf\Exception\FpdfException;
use PXP\PDF\Fpdf\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Object\Base\PDFName;
use PXP\PDF\Fpdf\Stream\StreamDecoder;

/**
 * @covers \PXP\PDF\Fpdf\Stream\StreamDecoder
 */
final class StreamDecoderTest extends TestCase
{
    private StreamDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new StreamDecoder();
    }

    public function testDecodeFlate(): void
    {
        if (!function_exists('gzcompress')) {
            $this->markTestSkipped('zlib extension not available');
        }

        $original = 'test data';
        $compressed = gzcompress($original);

        $dict = new PDFDictionary();
        $dict->addEntry('/Filter', new PDFName('FlateDecode'));

        $decoded = $this->decoder->decode($compressed, $dict);
        $this->assertSame($original, $decoded);
    }

    public function testDecodeFlateThrowsExceptionWhenZlibNotAvailable(): void
    {
        if (function_exists('gzuncompress')) {
            $this->markTestSkipped('zlib extension is available');
        }

        $this->expectException(FpdfException::class);
        $this->decoder->decodeFlate('test');
    }

    public function testDecodeDCT(): void
    {
        $data = 'jpeg data';
        $decoded = $this->decoder->decodeDCT($data);
        $this->assertSame($data, $decoded);
    }

    public function testDecodeRunLength(): void
    {
        // RunLength encoded data: literal run of 1 byte "a" (count 0 means 1 byte)
        $encoded = "\x00a\x80";
        $decoded = $this->decoder->decodeRunLength($encoded);
        $this->assertSame('a', $decoded);
    }

    public function testDecodeASCIIHex(): void
    {
        $hex = '74657374>';
        $decoded = $this->decoder->decodeASCIIHex($hex);
        $this->assertSame('test', $decoded);
    }

    public function testDecodeASCII85(): void
    {
        $ascii85 = 'FCfN8~>';
        $decoded = $this->decoder->decodeASCII85($ascii85);
        $this->assertIsString($decoded);
    }

    public function testDecodeWithNoFilter(): void
    {
        $data = 'test data';
        $dict = new PDFDictionary();

        $decoded = $this->decoder->decode($data, $dict);
        $this->assertSame($data, $decoded);
    }

    public function testDecodeThrowsExceptionForUnknownFilter(): void
    {
        $dict = new PDFDictionary();
        $dict->addEntry('/Filter', new PDFName('UnknownFilter'));

        $this->expectException(FpdfException::class);
        $this->decoder->decode('test', $dict);
    }
}
