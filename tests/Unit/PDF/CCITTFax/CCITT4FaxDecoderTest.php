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

use function count;
use function fclose;
use function file_exists;
use function file_get_contents;
use function fopen;
use function memory_get_usage;
use function rewind;
use function stream_get_contents;
use function strlen;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PXP\PDF\CCITTFax\BitmapPacker;
use PXP\PDF\CCITTFax\CCITT4FaxDecoder;
use RuntimeException;

/**
 * Test CCITT Group 4 (T.6) Fax Decoder.
 *
 * Tests both in-memory and streaming modes with real test files.
 */
final class CCITT4FaxDecoderTest extends TestCase
{
    private string $testFilesDir;

    public static function group4TestFilesProvider(): array
    {
        return [
            'small 18x18'  => ['18x18.bin', 18, 40],
            'small b0'     => ['b0.bin', 8, 8],
            'small b1'     => ['b1.bin', 8, 8],
            'small b2'     => ['b2.bin', 8, 8],
            'small b4'     => ['b4.bin', 8, 8],
            'small b5'     => ['b5.bin', 8, 8],
            'small b6'     => ['b6.bin', 8, 8],
            'small b8'     => ['b8.bin', 8, 8],
            'small b9'     => ['b9.bin', 8, 8],
            'medium 80x80' => ['80x80reversed.bin', 80, 800],
        ];
    }

    protected function setUp(): void
    {
        $this->testFilesDir = __DIR__ . '/../../../resources/CCITTFax/testfiles';
    }

    /**
     * @dataProvider group4TestFilesProvider
     */
    #[DataProvider('group4TestFilesProvider')]
    public function test_decode_group4_files(string $filename, int $width, int $expectedMinBytes): void
    {
        $filePath = $this->testFilesDir . '/' . $filename;
        $this->assertFileExists($filePath, "Test file not found: {$filename}");

        $compressedData = file_get_contents($filePath);
        $this->assertNotFalse($compressedData, "Failed to read file: {$filename}");

        $decoder = new CCITT4FaxDecoder($width, $compressedData, false);

        // Test legacy decode() method
        $lines = $decoder->decode();
        $this->assertIsArray($lines);
        $this->assertGreaterThan(0, count($lines), "No lines decoded from {$filename}");

        // Pack and verify size
        $packed = BitmapPacker::packLines($lines, $width);
        $this->assertGreaterThanOrEqual(
            $expectedMinBytes,
            strlen($packed),
            "Packed data too small for {$filename}",
        );
    }

    /**
     * @dataProvider group4TestFilesProvider
     */
    #[DataProvider('group4TestFilesProvider')]
    public function test_decode_group4_streaming_mode(string $filename, int $width, int $expectedMinBytes): void
    {
        $filePath       = $this->testFilesDir . '/' . $filename;
        $compressedData = file_get_contents($filePath);

        $decoder = new CCITT4FaxDecoder($width, $compressedData, false);

        // Test decodeToStream() method
        $outputStream = fopen('php://temp', 'rb+');
        $this->assertIsResource($outputStream);

        $bytesWritten = $decoder->decodeToStream($outputStream);
        $this->assertGreaterThanOrEqual(
            $expectedMinBytes,
            $bytesWritten,
            "Not enough bytes written for {$filename}",
        );

        rewind($outputStream);
        $streamOutput = stream_get_contents($outputStream);
        fclose($outputStream);

        $this->assertEquals($bytesWritten, strlen($streamOutput));
    }

    public function test_decode_and_stream_produce_same_output(): void
    {
        $filePath       = $this->testFilesDir . '/18x18.bin';
        $compressedData = file_get_contents($filePath);
        $this->assertNotFalse($compressedData, 'Failed to read test file');
        $width = 18;

        // Decode using legacy method
        $decoder1     = new CCITT4FaxDecoder($width, $compressedData, false);
        $lines        = $decoder1->decode();
        $legacyOutput = BitmapPacker::packLines($lines, $width);

        // Decode using streaming method
        $decoder2 = new CCITT4FaxDecoder($width, $compressedData, false);
        $stream   = fopen('php://temp', 'rb+');
        $decoder2->decodeToStream($stream);
        rewind($stream);
        $streamOutput = stream_get_contents($stream);
        fclose($stream);

        // Both methods should produce identical output
        $this->assertEquals($legacyOutput, $streamOutput);
    }

    public function test_decode_with_stream_input(): void
    {
        $filePath = $this->testFilesDir . '/18x18.bin';
        $width    = 18;

        // Open file as stream
        $inputStream = fopen($filePath, 'rb');
        $this->assertIsResource($inputStream);

        $decoder      = new CCITT4FaxDecoder($width, $inputStream, false);
        $outputStream = fopen('php://temp', 'rb+');

        $bytesWritten = $decoder->decodeToStream($outputStream);
        $this->assertGreaterThan(0, $bytesWritten);

        fclose($inputStream);
        fclose($outputStream);
    }

    public function test_decode_with_reverse_color(): void
    {
        $filePath       = $this->testFilesDir . '/18x18.bin';
        $compressedData = file_get_contents($filePath);
        $this->assertNotFalse($compressedData, 'Failed to read test file');
        $width = 18;

        // Decode with reverseColor = true
        $decoder = new CCITT4FaxDecoder($width, $compressedData, true);
        $lines   = $decoder->decode();

        $this->assertIsArray($lines);
        $this->assertGreaterThan(0, count($lines));
    }

    public function test_invalid_stream_throws_exception(): void
    {
        $decoder = new CCITT4FaxDecoder(18, "\x00\x10\x01\x00", false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Output must be a valid stream resource');

        $decoder->decodeToStream('not a stream');
    }

    public function test_get_width(): void
    {
        $decoder = new CCITT4FaxDecoder(1728, "\x00", false);
        $this->assertEquals(1728, $decoder->getWidth());
    }

    public function test_get_height_returns_zero(): void
    {
        // Group 4 doesn't know height beforehand
        $decoder = new CCITT4FaxDecoder(1728, "\x00", false);
        $this->assertEquals(0, $decoder->getHeight());
    }

    public function test_decode_empty_data(): void
    {
        $decoder = new CCITT4FaxDecoder(18, "\x00\x00\x00\x00", false);
        $lines   = $decoder->decode();

        // Should return empty or minimal lines
        $this->assertIsArray($lines);
    }

    public function test_streaming_memory_efficiency(): void
    {
        $filePath       = $this->testFilesDir . '/18x18.bin';
        $compressedData = file_get_contents($filePath);
        $this->assertNotFalse($compressedData, 'Failed to read test file');

        $memBefore = memory_get_usage(true);

        $decoder = new CCITT4FaxDecoder(18, $compressedData, false);
        $stream  = fopen('php://temp', 'rb+');
        $decoder->decodeToStream($stream);
        fclose($stream);

        $memAfter = memory_get_usage(true);
        $memUsed  = $memAfter - $memBefore;

        // Memory usage should be reasonable (< 1MB for small test)
        $this->assertLessThan(1024 * 1024, $memUsed, 'Memory usage too high for streaming mode');
    }

    public function test_decode_80x80_reversed(): void
    {
        $filePath = $this->testFilesDir . '/80x80reversed.bin';

        if (!file_exists($filePath)) {
            $this->markTestSkipped('Test file 80x80reversed.bin not found');
        }

        $compressedData = file_get_contents($filePath);
        $decoder        = new CCITT4FaxDecoder(80, $compressedData, false);

        $lines = $decoder->decode();
        $this->assertIsArray($lines);
        $this->assertGreaterThan(0, count($lines));
    }
}
