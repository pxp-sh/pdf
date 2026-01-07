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
use function str_repeat;
use function stream_get_contents;
use function strlen;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PXP\PDF\CCITTFax\Decoder\CCITT3Decoder;
use PXP\PDF\CCITTFax\Model\Params;
use PXP\PDF\CCITTFax\Util\BitmapPacker;
use RuntimeException;

/**
 * Test CCITT Group 3 1D (T.4) Fax Decoder.
 *
 * Tests Modified Huffman encoding (K=0) with both in-memory and streaming modes.
 */
final class CCITT3DecoderTest extends TestCase
{
    private string $testFilesDir;

    public static function parameterCombinationsProvider(): array
    {
        return [
            'standard fax' => [0, 1728, 2200, false, false],
            'with eol'     => [0, 1728, 2200, true, false],
            'inverted'     => [0, 1728, 2200, false, true],
            'small'        => [0, 100, 100, false, false],
        ];
    }

    protected function setUp(): void
    {
        $this->testFilesDir = __DIR__ . '/../../../../resources/CCITTFax/testfiles';
    }

    public function test_decode_with_default_params(): void
    {
        $params = new Params(
            k: 0,  // Group 3 1D
            columns: 18,
            rows: 18,
        );

        $filePath = $this->testFilesDir . '/18x18.bin';

        if (!file_exists($filePath)) {
            $this->markTestSkipped('Test file not found: 18x18.bin');
        }

        $compressedData = file_get_contents($filePath);
        $decoder        = new CCITT3Decoder($params, $compressedData);

        $lines = $decoder->decode();
        $this->assertIsArray($lines);
        $this->assertGreaterThan(0, count($lines));
    }

    public function test_decode_to_stream(): void
    {
        $params = new Params(
            k: 0,
            columns: 18,
            rows: 18,
            endOfLine: false,
        );

        $filePath = $this->testFilesDir . '/18x18.bin';

        if (!file_exists($filePath)) {
            $this->markTestSkipped('Test file not found');
        }

        $compressedData = file_get_contents($filePath);
        $decoder        = new CCITT3Decoder($params, $compressedData);

        $outputStream = fopen('php://temp', 'rb+');
        $bytesWritten = $decoder->decodeToStream($outputStream);

        $this->assertGreaterThan(0, $bytesWritten);

        rewind($outputStream);
        $output = stream_get_contents($outputStream);
        fclose($outputStream);

        $this->assertEquals($bytesWritten, strlen($output));
    }

    public function test_decode_and_stream_produce_same_output(): void
    {
        $params = new Params(
            k: 0,
            columns: 18,
            rows: 18,
        );

        $filePath = $this->testFilesDir . '/18x18.bin';

        if (!file_exists($filePath)) {
            $this->markTestSkipped('Test file not found');
        }

        $compressedData = file_get_contents($filePath);

        // Legacy decode
        $decoder1     = new CCITT3Decoder($params, $compressedData);
        $lines        = $decoder1->decode();
        $legacyOutput = BitmapPacker::packLines($lines, $params->getColumns());

        // Streaming decode
        $decoder2 = new CCITT3Decoder($params, $compressedData);
        $stream   = fopen('php://temp', 'rb+');
        $decoder2->decodeToStream($stream);
        rewind($stream);
        $streamOutput = stream_get_contents($stream);
        fclose($stream);

        $this->assertEquals($legacyOutput, $streamOutput);
    }

    public function test_decode_with_eol_markers(): void
    {
        $params = new Params(
            k: 0,
            columns: 1728,
            rows: 0,
            endOfLine: true,
            encodedByteAlign: false,
        );

        // Create simple test data with EOL markers
        $testData = "\x00\x10" . str_repeat("\xFF", 100);
        $decoder  = new CCITT3Decoder($params, $testData);

        try {
            $lines = $decoder->decode();
            $this->assertIsArray($lines);
        } catch (RuntimeException $e) {
            // Expected for invalid test data
            $this->assertStringContainsString('Invalid', $e->getMessage());
        }
    }

    public function test_decode_with_blackis1(): void
    {
        $params = new Params(
            k: 0,
            columns: 18,
            rows: 18,
            blackIs1: true,
        );

        $filePath = $this->testFilesDir . '/18x18.bin';

        if (!file_exists($filePath)) {
            $this->markTestSkipped('Test file not found');
        }

        $compressedData = file_get_contents($filePath);
        $decoder        = new CCITT3Decoder($params, $compressedData);

        $lines = $decoder->decode();
        $this->assertIsArray($lines);
    }

    public function test_decode_with_stream_input(): void
    {
        $params   = new Params(k: 0, columns: 18, rows: 18);
        $filePath = $this->testFilesDir . '/18x18.bin';

        if (!file_exists($filePath)) {
            $this->markTestSkipped('Test file not found');
        }

        $inputStream = fopen($filePath, 'rb');
        $this->assertIsResource($inputStream);

        $decoder      = new CCITT3Decoder($params, $inputStream);
        $outputStream = fopen('php://temp', 'rb+');

        $bytesWritten = $decoder->decodeToStream($outputStream);
        $this->assertGreaterThan(0, $bytesWritten);

        fclose($inputStream);
        fclose($outputStream);
    }

    public function test_get_width_and_height(): void
    {
        $params  = new Params(k: 0, columns: 1728, rows: 2200);
        $decoder = new CCITT3Decoder($params, "\x00");

        $this->assertEquals(1728, $decoder->getWidth());
        $this->assertEquals(2200, $decoder->getHeight());
    }

    public function test_invalid_stream_throws_exception(): void
    {
        $params  = new Params(k: 0, columns: 18);
        $decoder = new CCITT3Decoder($params, "\x00");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Output must be a valid stream resource');

        $decoder->decodeToStream('not a stream');
    }

    public function test_decode_empty_data(): void
    {
        $params  = new Params(k: 0, columns: 18, rows: 1);
        $decoder = new CCITT3Decoder($params, "\x00\x00\x00\x00");

        $lines = $decoder->decode();
        $this->assertIsArray($lines);
    }

    public function test_decode_with_damaged_rows_tolerance(): void
    {
        $params = new Params(
            k: 0,
            columns: 18,
            rows: 5,
            endOfLine: true,
            damagedRowsBeforeError: 3,  // Allow 3 damaged rows
        );

        $testData = str_repeat("\xFF\xFF", 50);
        $decoder  = new CCITT3Decoder($params, $testData);

        try {
            $lines = $decoder->decode();
            $this->assertIsArray($lines);
        } catch (RuntimeException $e) {
            // Expected for heavily damaged data
            $this->assertStringContainsString('damaged', $e->getMessage());
        }
    }

    public function test_streaming_mode_memory_efficiency(): void
    {
        $params   = new Params(k: 0, columns: 18, rows: 18);
        $filePath = $this->testFilesDir . '/18x18.bin';

        if (!file_exists($filePath)) {
            $this->markTestSkipped('Test file not found');
        }

        $compressedData = file_get_contents($filePath);
        $memBefore      = memory_get_usage(true);

        $decoder = new CCITT3Decoder($params, $compressedData);
        $stream  = fopen('php://temp', 'rb+');
        $decoder->decodeToStream($stream);
        fclose($stream);

        $memAfter = memory_get_usage(true);
        $memUsed  = $memAfter - $memBefore;

        // Should use minimal memory
        $this->assertLessThan(1024 * 1024, $memUsed);
    }

    public function test_params_validation(): void
    {
        $params = new Params(k: 0, columns: 8);

        $this->assertTrue($params->isPure1D());
        $this->assertSame(8, $params->getColumns());
    }

    public function test_decoder_instantiation(): void
    {
        $params  = new Params(k: 0, columns: 8);
        $decoder = new CCITT3Decoder($params, '');

        $this->assertInstanceOf(CCITT3Decoder::class, $decoder);
    }

    /**
     * @dataProvider parameterCombinationsProvider
     */
    #[DataProvider('parameterCombinationsProvider')]
    public function test_various_parameter_combinations(
        int $k,
        int $columns,
        int $rows,
        bool $endOfLine,
        bool $blackIs1
    ): void {
        $params = new Params(
            k: $k,
            columns: $columns,
            rows: $rows,
            endOfLine: $endOfLine,
            blackIs1: $blackIs1,
        );

        $this->assertTrue($params->isPure1D());
        $this->assertEquals($columns, $params->getColumns());
        $this->assertEquals($rows, $params->getRows());
    }
}
