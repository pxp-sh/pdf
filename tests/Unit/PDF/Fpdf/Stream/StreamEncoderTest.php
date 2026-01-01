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
use PXP\PDF\Fpdf\Stream\StreamEncoder;

/**
 * @covers \PXP\PDF\Fpdf\Stream\StreamEncoder
 */
final class StreamEncoderTest extends TestCase
{
    private StreamEncoder $encoder;

    protected function setUp(): void
    {
        $this->encoder = new StreamEncoder();
    }

    public function testEncodeFlate(): void
    {
        if (!function_exists('gzcompress')) {
            $this->markTestSkipped('zlib extension not available');
        }

        $data = 'test data';
        $encoded = $this->encoder->encodeFlate($data);

        $this->assertNotSame($data, $encoded);
        $this->assertIsString($encoded);
    }

    public function testEncodeFlateThrowsExceptionWhenZlibNotAvailable(): void
    {
        if (function_exists('gzcompress')) {
            $this->markTestSkipped('zlib extension is available');
        }

        $this->expectException(FpdfException::class);
        $this->encoder->encodeFlate('test');
    }

    public function testEncodeWithMultipleFilters(): void
    {
        if (!function_exists('gzcompress')) {
            $this->markTestSkipped('zlib extension not available');
        }

        $data = 'test data';
        $encoded = $this->encoder->encode($data, ['FlateDecode']);

        $this->assertIsString($encoded);
    }

    public function testEncodeThrowsExceptionForUnknownFilter(): void
    {
        $this->expectException(FpdfException::class);
        $this->encoder->encode('test', ['UnknownFilter']);
    }
}
