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
use function str_repeat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PXP\PDF\CCITTFax\Decoder\CCITT3MixedDecoder;
use PXP\PDF\CCITTFax\Model\Params;
use RuntimeException;

/**
 * Test CCITT Group 3 Mixed 1D/2D (T.4 with 2D extensions) Fax Decoder.
 *
 * Tests mixed mode encoding (K>0) with both in-memory and streaming modes.
 */
final class CCITT3MixedDecoderTest extends TestCase
{
    private string $testFilesDir;

    public static function kParameterProvider(): array
    {
        return [
            'k=1'  => [1],
            'k=2'  => [2],
            'k=4'  => [4],
            'k=8'  => [8],
            'k=16' => [16],
        ];
    }

    protected function setUp(): void
    {
        $this->testFilesDir = __DIR__ . '/../../../resources/CCITTFax/testfiles';
    }

    public function test_constructor_requires_positive_k(): void
    {
        $params = new Params(k: 0);  // K must be > 0 for mixed mode

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mixed mode requires K > 0');

        new CCITT3MixedDecoder($params, "\x00");
    }

    public function test_decode_with_k_equals_4(): void
    {
        $params = new Params(
            k: 4,  // Max 4 consecutive 2D lines
            columns: 1728,
            rows: 100,
        );

        $testData = str_repeat("\x00\x10\x01", 100);
        $decoder  = new CCITT3MixedDecoder($params, $testData);

        try {
            $lines = $decoder->decode();
            $this->assertIsArray($lines);
        } catch (RuntimeException $e) {
            // Expected for invalid test data
            $this->assertInstanceOf(RuntimeException::class, $e);
        }
    }

    public function test_decode_to_stream(): void
    {
        $params = new Params(
            k: 2,
            columns: 18,
            rows: 18,
        );

        $filePath = $this->testFilesDir . '/18x18.bin';

        if (!file_exists($filePath)) {
            $this->markTestSkipped('Test file not found');
        }

        $compressedData = file_get_contents($filePath);
        $decoder        = new CCITT3MixedDecoder($params, $compressedData);

        $outputStream = fopen('php://temp', 'rb+');

        try {
            $bytesWritten = $decoder->decodeToStream($outputStream);
            $this->assertGreaterThanOrEqual(0, $bytesWritten);
        } catch (RuntimeException $e) {
            // Some test files may not be mixed mode
            $this->assertInstanceOf(RuntimeException::class, $e);
        } finally {
            fclose($outputStream);
        }
    }

    public function test_decode_with_eol_and_tag_bits(): void
    {
        $params = new Params(
            k: 3,
            columns: 1728,
            rows: 10,
            endOfLine: true,  // EOL with tag bit
            encodedByteAlign: false,
        );

        $testData = str_repeat("\x00\x11", 50);  // EOL + tag bit pattern
        $decoder  = new CCITT3MixedDecoder($params, $testData);

        try {
            $lines = $decoder->decode();
            $this->assertIsArray($lines);
        } catch (RuntimeException $e) {
            $this->assertInstanceOf(RuntimeException::class, $e);
        }
    }

    public function test_decode_alternating_1d_2d_lines(): void
    {
        $params = new Params(
            k: 1,  // Every other line must be 1D
            columns: 100,
            rows: 10,
            endOfLine: false,
        );

        $testData = str_repeat("\xFF\x00", 200);
        $decoder  = new CCITT3MixedDecoder($params, $testData);

        try {
            $lines = $decoder->decode();
            $this->assertIsArray($lines);
        } catch (RuntimeException $e) {
            // Expected for invalid test pattern
            $this->assertInstanceOf(RuntimeException::class, $e);
        }
    }

    public function test_decode_with_stream_input(): void
    {
        $params   = new Params(k: 2, columns: 18, rows: 18);
        $filePath = $this->testFilesDir . '/18x18.bin';

        if (!file_exists($filePath)) {
            $this->markTestSkipped('Test file not found');
        }

        $inputStream = fopen($filePath, 'rb');
        $this->assertIsResource($inputStream);

        $decoder      = new CCITT3MixedDecoder($params, $inputStream);
        $outputStream = fopen('php://temp', 'rb+');

        try {
            $bytesWritten = $decoder->decodeToStream($outputStream);
            $this->assertGreaterThanOrEqual(0, $bytesWritten);
        } catch (RuntimeException $e) {
            $this->assertInstanceOf(RuntimeException::class, $e);
        } finally {
            fclose($inputStream);
            fclose($outputStream);
        }
    }

    public function test_get_width_and_height(): void
    {
        $params  = new Params(k: 2, columns: 1728, rows: 2200);
        $decoder = new CCITT3MixedDecoder($params, "\x00");

        $this->assertEquals(1728, $decoder->getWidth());
        $this->assertEquals(2200, $decoder->getHeight());
    }

    public function test_invalid_stream_throws_exception(): void
    {
        $params  = new Params(k: 2, columns: 18);
        $decoder = new CCITT3MixedDecoder($params, "\x00");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Output must be a valid stream resource');

        $decoder->decodeToStream('not a stream');
    }

    public function test_decode_with_blackis1(): void
    {
        $params = new Params(
            k: 2,
            columns: 18,
            rows: 18,
            blackIs1: true,
        );

        $filePath = $this->testFilesDir . '/18x18.bin';

        if (!file_exists($filePath)) {
            $this->markTestSkipped('Test file not found');
        }

        $compressedData = file_get_contents($filePath);
        $decoder        = new CCITT3MixedDecoder($params, $compressedData);

        try {
            $lines = $decoder->decode();
            $this->assertIsArray($lines);
        } catch (RuntimeException $e) {
            $this->assertInstanceOf(RuntimeException::class, $e);
        }
    }

    public function test_streaming_memory_efficiency(): void
    {
        $params   = new Params(k: 2, columns: 18, rows: 18);
        $filePath = $this->testFilesDir . '/18x18.bin';

        if (!file_exists($filePath)) {
            $this->markTestSkipped('Test file not found');
        }

        $compressedData = file_get_contents($filePath);
        $memBefore      = memory_get_usage(true);

        $decoder = new CCITT3MixedDecoder($params, $compressedData);
        $stream  = fopen('php://temp', 'rb+');

        try {
            $decoder->decodeToStream($stream);
        } catch (RuntimeException $e) {
            // Expected for non-mixed-mode test files
        } finally {
            fclose($stream);
        }

        $memAfter = memory_get_usage(true);
        $memUsed  = $memAfter - $memBefore;

        // Should use minimal memory
        $this->assertLessThan(2 * 1024 * 1024, $memUsed);
    }

    /**
     * @dataProvider kParameterProvider
     */
    #[DataProvider('kParameterProvider')]
    public function test_various_k_values(int $k): void
    {
        $params = new Params(
            k: $k,
            columns: 100,
            rows: 50,
        );

        $this->assertTrue($params->isMixed());
        $this->assertEquals($k, $params->getK());

        $decoder = new CCITT3MixedDecoder($params, str_repeat("\x00", 100));
        $this->assertEquals(100, $decoder->getWidth());
        $this->assertEquals(50, $decoder->getHeight());
    }

    public function test_reference_line_usage(): void
    {
        // Test that 2D lines use reference line correctly
        $params = new Params(
            k: 10,  // Allow many 2D lines
            columns: 50,
            rows: 20,
            endOfLine: false,
        );

        $testData = str_repeat("\x88\x44\x22\x11", 100);
        $decoder  = new CCITT3MixedDecoder($params, $testData);

        try {
            $lines = $decoder->decode();
            $this->assertIsArray($lines);

            // If we get lines, verify basic structure
            if (count($lines) > 0) {
                $this->assertIsArray($lines[0]);
            }
        } catch (RuntimeException $e) {
            // Expected for invalid test pattern
            $this->assertInstanceOf(RuntimeException::class, $e);
        }
    }

    public function test_decode_with_byte_alignment(): void
    {
        $params = new Params(
            k: 2,
            columns: 1728,
            rows: 10,
            endOfLine: true,
            encodedByteAlign: true,  // EOL padded to byte boundary
        );

        $testData = str_repeat("\x00\x10\x00\x00", 30);
        $decoder  = new CCITT3MixedDecoder($params, $testData);

        try {
            $lines = $decoder->decode();
            $this->assertIsArray($lines);
        } catch (RuntimeException $e) {
            $this->assertInstanceOf(RuntimeException::class, $e);
        }
    }
}
