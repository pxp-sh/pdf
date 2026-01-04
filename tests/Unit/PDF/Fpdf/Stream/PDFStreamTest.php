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
use PXP\PDF\Fpdf\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Stream\PDFStream;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Stream\PDFStream
 */
final class PDFStreamTest extends TestCase
{
    public function testGetDecodedData(): void
    {
        $dict   = new PDFDictionary;
        $stream = new PDFStream($dict, 'test data', false);

        $this->assertSame('test data', $stream->getDecodedData());
    }

    public function testSetData(): void
    {
        $dict   = new PDFDictionary;
        $stream = new PDFStream($dict, 'old data', false);
        $stream->setData('new data');

        $this->assertSame('new data', $stream->getDecodedData());
    }

    public function testAddFilter(): void
    {
        if (!function_exists('gzcompress')) {
            $this->markTestSkipped('zlib extension not available');
        }

        $dict   = new PDFDictionary;
        $stream = new PDFStream($dict, 'test data', false);
        $stream->addFilter('FlateDecode');

        $this->assertTrue($stream->hasFilter('FlateDecode'));
        $encoded = $stream->getEncodedData();
        $this->assertNotSame('test data', $encoded);
    }

    public function testRemoveFilter(): void
    {
        $dict   = new PDFDictionary;
        $stream = new PDFStream($dict, 'test data', false);
        $stream->addFilter('FlateDecode');
        $stream->removeFilter('FlateDecode');

        $this->assertFalse($stream->hasFilter('FlateDecode'));
    }

    public function testToStringIncludesStream(): void
    {
        $dict   = new PDFDictionary;
        $stream = new PDFStream($dict, 'test data', false);

        $result = (string) $stream;
        $this->assertStringContainsString('stream', $result);
        $this->assertStringContainsString('endstream', $result);
        $this->assertStringContainsString('test data', $result);
    }

    public function testGetEncodedDataCachesResult(): void
    {
        if (!function_exists('gzcompress')) {
            $this->markTestSkipped('zlib extension not available');
        }

        $dict   = new PDFDictionary;
        $stream = new PDFStream($dict, 'test data', false);
        $stream->addFilter('FlateDecode');

        $encoded1 = $stream->getEncodedData();
        $encoded2 = $stream->getEncodedData();

        $this->assertSame($encoded1, $encoded2);
    }
}
