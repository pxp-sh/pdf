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
use PXP\PDF\Fpdf\Core\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Core\Stream\PDFStream;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Stream\PDFStream
 */
final class PDFStreamTest extends TestCase
{
    public function testGetDecodedData(): void
    {
        $pdfDictionary = new PDFDictionary;
        $pdfStream     = new PDFStream($pdfDictionary, 'test data', false);

        $this->assertSame('test data', $pdfStream->getDecodedData());
    }

    public function testSetData(): void
    {
        $pdfDictionary = new PDFDictionary;
        $pdfStream     = new PDFStream($pdfDictionary, 'old data', false);
        $pdfStream->setData('new data');

        $this->assertSame('new data', $pdfStream->getDecodedData());
    }

    public function testAddFilter(): void
    {
        if (!function_exists('gzcompress')) {
            $this->markTestSkipped('zlib extension not available');
        }

        $pdfDictionary = new PDFDictionary;
        $pdfStream     = new PDFStream($pdfDictionary, 'test data', false);
        $pdfStream->addFilter('FlateDecode');

        $this->assertTrue($pdfStream->hasFilter('FlateDecode'));
        $encoded = $pdfStream->getEncodedData();
        $this->assertNotSame('test data', $encoded);
    }

    public function testRemoveFilter(): void
    {
        $pdfDictionary = new PDFDictionary;
        $pdfStream     = new PDFStream($pdfDictionary, 'test data', false);
        $pdfStream->addFilter('FlateDecode');
        $pdfStream->removeFilter('FlateDecode');

        $this->assertFalse($pdfStream->hasFilter('FlateDecode'));
    }

    public function testToStringIncludesStream(): void
    {
        $pdfDictionary = new PDFDictionary;
        $pdfStream     = new PDFStream($pdfDictionary, 'test data', false);

        $result = (string) $pdfStream;
        $this->assertStringContainsString('stream', $result);
        $this->assertStringContainsString('endstream', $result);
        $this->assertStringContainsString('test data', $result);
    }

    public function testGetEncodedDataCachesResult(): void
    {
        if (!function_exists('gzcompress')) {
            $this->markTestSkipped('zlib extension not available');
        }

        $pdfDictionary = new PDFDictionary;
        $pdfStream     = new PDFStream($pdfDictionary, 'test data', false);
        $pdfStream->addFilter('FlateDecode');

        $encoded1 = $pdfStream->getEncodedData();
        $encoded2 = $pdfStream->getEncodedData();

        $this->assertSame($encoded1, $encoded2);
    }
}
