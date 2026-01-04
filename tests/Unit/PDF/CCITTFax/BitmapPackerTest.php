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
namespace Tests\Unit\PDF\CCITTFax;

use function array_fill;
use function array_merge;
use function fclose;
use function fopen;
use function ord;
use function rewind;
use function stream_get_contents;
use function strlen;
use PHPUnit\Framework\TestCase;
use PXP\PDF\CCITTFax\BitmapPacker;
use RuntimeException;

final class BitmapPackerTest extends TestCase
{
    public function test_pack_single_line_all_white(): void
    {
        $lines = [
            [0, 0, 0, 0, 0, 0, 0, 0], // 8 white pixels
        ];

        $packed = BitmapPacker::packLines($lines, 8);

        $this->assertSame(1, strlen($packed));
        $this->assertSame(0b00000000, ord($packed[0]));
    }

    public function test_pack_single_line_all_black(): void
    {
        $lines = [
            [255, 255, 255, 255, 255, 255, 255, 255], // 8 black pixels
        ];

        $packed = BitmapPacker::packLines($lines, 8);

        $this->assertSame(1, strlen($packed));
        $this->assertSame(0b11111111, ord($packed[0]));
    }

    public function test_pack_single_line_alternating(): void
    {
        $lines = [
            [255, 0, 255, 0, 255, 0, 255, 0], // Alternating black/white
        ];

        $packed = BitmapPacker::packLines($lines, 8);

        $this->assertSame(1, strlen($packed));
        $this->assertSame(0b10101010, ord($packed[0]));
    }

    public function test_pack_partial_byte(): void
    {
        $lines = [
            [255, 255, 255, 0, 0], // 5 pixels: 3 black, 2 white
        ];

        $packed = BitmapPacker::packLines($lines, 5);

        $this->assertSame(1, strlen($packed));
        // 11100000 (3 black, 2 white, 3 padding zeros)
        $this->assertSame(0b11100000, ord($packed[0]));
    }

    public function test_pack_multiple_bytes_per_line(): void
    {
        $lines = [
            array_merge(
                array_fill(0, 8, 255), // First byte: all black
                array_fill(0, 8, 0),    // Second byte: all white
            ),
        ];

        $packed = BitmapPacker::packLines($lines, 16);

        $this->assertSame(2, strlen($packed));
        $this->assertSame(0b11111111, ord($packed[0]));
        $this->assertSame(0b00000000, ord($packed[1]));
    }

    public function test_pack_multiple_lines(): void
    {
        $lines = [
            [255, 255, 255, 255, 255, 255, 255, 255], // Line 1: all black
            [0, 0, 0, 0, 0, 0, 0, 0],                 // Line 2: all white
        ];

        $packed = BitmapPacker::packLines($lines, 8);

        $this->assertSame(2, strlen($packed));
        $this->assertSame(0b11111111, ord($packed[0]));
        $this->assertSame(0b00000000, ord($packed[1]));
    }

    public function test_unpack_data(): void
    {
        // Pack then unpack should give original data
        $original = [
            [255, 0, 255, 0, 255, 0, 255, 0],
            [0, 255, 0, 255, 0, 255, 0, 255],
        ];

        $packed   = BitmapPacker::packLines($original, 8);
        $unpacked = BitmapPacker::unpackData($packed, 8, 2);

        $this->assertEquals($original, $unpacked);
    }

    public function test_to_uncompressed_bytes(): void
    {
        $lines = [
            [0, 255, 0],
            [255, 0, 255],
        ];

        $uncompressed = BitmapPacker::toUncompressedBytes($lines);

        $this->assertSame(6, strlen($uncompressed));
        $this->assertSame(0, ord($uncompressed[0]));
        $this->assertSame(255, ord($uncompressed[1]));
        $this->assertSame(0, ord($uncompressed[2]));
        $this->assertSame(255, ord($uncompressed[3]));
        $this->assertSame(0, ord($uncompressed[4]));
        $this->assertSame(255, ord($uncompressed[5]));
    }

    public function test_calculate_packed_size(): void
    {
        $this->assertSame(1, BitmapPacker::calculatePackedSize(8, 1));
        $this->assertSame(2, BitmapPacker::calculatePackedSize(16, 1));
        $this->assertSame(2, BitmapPacker::calculatePackedSize(9, 1)); // Needs 2 bytes for 9 pixels
        $this->assertSame(1728 / 8, BitmapPacker::calculatePackedSize(1728, 1)); // Standard fax width
        $this->assertSame(1728 / 8 * 100, BitmapPacker::calculatePackedSize(1728, 100));
    }

    public function test_invert_colors(): void
    {
        $lines = [
            [0, 255, 0, 255],
            [255, 0, 255, 0],
        ];

        $inverted = BitmapPacker::invertColors($lines);

        $expected = [
            [255, 0, 255, 0],
            [0, 255, 0, 255],
        ];

        $this->assertEquals($expected, $inverted);
    }

    public function test_pack_standard_fax_width(): void
    {
        // Standard fax width is 1728 pixels = 216 bytes per line
        $line  = array_fill(0, 1728, 0);
        $lines = [$line];

        $packed = BitmapPacker::packLines($lines, 1728);

        $this->assertSame(216, strlen($packed), '1728 pixels should pack to 216 bytes');
    }

    public function test_msb_first_order(): void
    {
        // Verify MSB-first bit order (leftmost pixel = bit 7)
        $lines = [
            [255, 0, 0, 0, 0, 0, 0, 0], // Only leftmost pixel is black
        ];

        $packed = BitmapPacker::packLines($lines, 8);

        $this->assertSame(0b10000000, ord($packed[0]), 'Leftmost pixel should be MSB (bit 7)');
    }

    public function test_pack_single_line_method(): void
    {
        $line   = [255, 0, 255, 0, 255, 0, 255, 0];
        $packed = BitmapPacker::packSingleLine($line, 8);

        $this->assertEquals(1, strlen($packed));
        $this->assertEquals("\xAA", $packed);
    }

    public function test_pack_lines_to_stream(): void
    {
        $lines = [
            [0, 0, 0, 0, 0, 0, 0, 0],
            [255, 255, 255, 255, 255, 255, 255, 255],
        ];

        $stream       = fopen('php://temp', 'rb+');
        $bytesWritten = BitmapPacker::packLinesToStream($stream, $lines, 8);

        $this->assertEquals(2, $bytesWritten);

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        $this->assertEquals("\x00\xFF", $output);
    }

    public function test_pack_to_stream_invalid_stream_throws_exception(): void
    {
        $lines = [[0, 0, 0, 0, 0, 0, 0, 0]];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stream must be a valid resource');

        BitmapPacker::packLinesToStream('not a stream', $lines, 8);
    }

    public function test_pack_lines_to_stream_large_data(): void
    {
        // Test with many lines to ensure streaming works efficiently
        $lines = [];

        for ($i = 0; $i < 100; $i++) {
            $lines[] = array_fill(0, 100, $i % 2 === 0 ? 0 : 255);
        }

        $stream       = fopen('php://temp', 'rb+');
        $bytesWritten = BitmapPacker::packLinesToStream($stream, $lines, 100);

        // 100 pixels per line = 13 bytes per line (ceil(100/8))
        // 100 lines * 13 bytes = 1300 bytes
        $this->assertEquals(1300, $bytesWritten);

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        $this->assertEquals(1300, strlen($output));
    }

    public function test_streaming_vs_legacy_produce_same_output(): void
    {
        $lines = [
            [255, 0, 255, 0, 255, 0, 255, 0],
            [0, 255, 0, 255, 0, 255, 0, 255],
            [255, 255, 0, 0, 255, 255, 0, 0],
        ];

        // Legacy method
        $legacyOutput = BitmapPacker::packLines($lines, 8);

        // Streaming method
        $stream = fopen('php://temp', 'rb+');
        BitmapPacker::packLinesToStream($stream, $lines, 8);
        rewind($stream);
        $streamOutput = stream_get_contents($stream);
        fclose($stream);

        $this->assertEquals($legacyOutput, $streamOutput);
    }
}
