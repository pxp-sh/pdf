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

use function function_exists;
use function gzcompress;
use PXP\PDF\Fpdf\Core\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Core\Object\Base\PDFName;
use PXP\PDF\Fpdf\Core\Stream\StreamDecoder;
use PXP\PDF\Fpdf\Exceptions\Exception\FpdfException;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Stream\StreamDecoder
 */
final class StreamDecoderTest extends TestCase
{
    private StreamDecoder $streamDecoder;

    protected function setUp(): void
    {
        $this->streamDecoder = new StreamDecoder;
    }

    public function testDecodeFlate(): void
    {
        if (!function_exists('gzcompress')) {
            $this->markTestSkipped('zlib extension not available');
        }

        $original   = 'test data';
        $compressed = gzcompress($original);

        $pdfDictionary = new PDFDictionary;
        $pdfDictionary->addEntry('/Filter', new PDFName('FlateDecode'));

        $decoded = $this->streamDecoder->decode($compressed, $pdfDictionary);
        $this->assertSame($original, $decoded);
    }

    public function testDecodeFlateThrowsExceptionWhenZlibNotAvailable(): void
    {
        if (function_exists('gzuncompress')) {
            $this->markTestSkipped('zlib extension is available');
        }

        $this->expectException(FpdfException::class);
        $this->streamDecoder->decodeFlate('test');
    }

    public function testDecodeDCT(): void
    {
        $data    = 'jpeg data';
        $decoded = $this->streamDecoder->decodeDCT($data);
        $this->assertSame($data, $decoded);
    }

    public function testDecodeRunLength(): void
    {
        // RunLength encoded data: literal run of 1 byte "a" (count 0 means 1 byte)
        $encoded = "\x00a\x80";
        $decoded = $this->streamDecoder->decodeRunLength($encoded);
        $this->assertSame('a', $decoded);
    }

    public function testDecodeASCIIHex(): void
    {
        $hex     = '74657374>';
        $decoded = $this->streamDecoder->decodeASCIIHex($hex);
        $this->assertSame('test', $decoded);
    }

    public function testDecodeASCII85(): void
    {
        $ascii85 = 'FCfN8~>';
        $decoded = $this->streamDecoder->decodeASCII85($ascii85);
        $this->assertIsString($decoded);
    }

    public function testDecodeWithNoFilter(): void
    {
        $data          = 'test data';
        $pdfDictionary = new PDFDictionary;

        $decoded = $this->streamDecoder->decode($data, $pdfDictionary);
        $this->assertSame($data, $decoded);
    }

    public function testDecodeThrowsExceptionForUnknownFilter(): void
    {
        $pdfDictionary = new PDFDictionary;
        $pdfDictionary->addEntry('/Filter', new PDFName('UnknownFilter'));

        $this->expectException(FpdfException::class);
        $this->streamDecoder->decode('test', $pdfDictionary);
    }
}
