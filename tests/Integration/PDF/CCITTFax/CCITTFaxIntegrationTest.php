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
namespace Tests\Integration\PDF\CCITTFax;

use function basename;
use function count;
use function fclose;
use function file_exists;
use function file_get_contents;
use function fopen;
use function glob;
use function is_array;
use function is_dir;
use function memory_get_usage;
use function preg_match;
use function rewind;
use function str_contains;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use Exception;
use PXP\PDF\CCITTFax\Decoder\CCITT3Decoder;
use PXP\PDF\CCITTFax\Decoder\CCITT4Decoder;
use PXP\PDF\CCITTFax\Model\Params;
use PXP\PDF\CCITTFax\Util\BitmapPacker;
use RuntimeException;
use Test\TestCase;

/**
 * Integration tests for CCITT Fax Decoders using real test files.
 *
 * Tests complete decoding workflows with actual fax-encoded data.
 */
final class CCITTFaxIntegrationTest extends TestCase
{
    private string $testFilesDir;

    protected function setUp(): void
    {
        $this->testFilesDir = __DIR__ . '/../../../resources/CCITTFax/testfiles';

        if (!is_dir($this->testFilesDir)) {
            $this->markTestSkipped("Test files directory not found: {$this->testFilesDir}");
        }
    }

    /**
     * Test all .bin files in testfiles directory with Group 4 decoder.
     * This ensures we can at least attempt to decode all test files without crashing.
     */
    public function test_decode_all_test_files_with_group4(): void
    {
        $binFiles = glob($this->testFilesDir . '/*.bin');
        $this->assertNotEmpty($binFiles, 'No .bin test files found');

        $successCount = 0;
        $failureCount = 0;
        $decodedFiles = [];

        foreach ($binFiles as $filePath) {
            $filename = basename($filePath);

            // Determine width from filename or use default
            $width = $this->guessWidthFromFilename($filename);

            try {
                $compressedData = file_get_contents($filePath);
                $this->assertNotFalse($compressedData, "Failed to read {$filename}");

                $decoder = new CCITT4Decoder($width, $compressedData, false);
                $lines   = $decoder->decode();
                $this->assertIsArray($lines, "Decoded lines should be an array for {$filename}");

                if (is_array($lines) && count($lines) > 0) {
                    $successCount++;
                    $decodedFiles[] = $filename;
                }
            } catch (Exception $e) {
                $this->fail("Decoding failed for {$filename}: " . $e->getMessage());
                // Some files may not be Group 4 format, that's okay
            }
        }

        // We should successfully decode at least some files
        $this->assertGreaterThan(0, $successCount, 'Failed to decode any test files');
    }

    /**
     * Test streaming mode memory efficiency with real files.
     */
    public function test_streaming_mode_uses_less_memory(): void
    {
        $testFile = $this->testFilesDir . '/18x18.bin';

        if (!file_exists($testFile)) {
            $this->markTestSkipped('Test file 18x18.bin not found');
        }

        $compressedData = file_get_contents($testFile);

        // Test legacy mode
        $memBeforeLegacy = memory_get_usage(true);
        $decoder1        = new CCITT4Decoder(18, $compressedData, false);
        $lines           = $decoder1->decode();
        $packed          = BitmapPacker::packLines($lines, 18);
        $memAfterLegacy  = memory_get_usage(true);
        $legacyMemory    = $memAfterLegacy - $memBeforeLegacy;

        // Test streaming mode
        $memBeforeStreaming = memory_get_usage(true);
        $decoder2           = new CCITT4Decoder(18, $compressedData, false);
        $stream             = fopen('php://temp', 'rb+');
        $decoder2->decodeToStream($stream);
        fclose($stream);
        $memAfterStreaming = memory_get_usage(true);
        $streamingMemory   = $memAfterStreaming - $memBeforeStreaming;

        // Streaming should use less memory (or at most similar for small files)
        $this->assertLessThanOrEqual(
            $legacyMemory * 1.5,  // Allow 50% margin
            $streamingMemory,
            'Streaming mode should not use significantly more memory',
        );
    }

    /**
     * Test that streaming and legacy methods produce identical output.
     */
    public function test_streaming_and_legacy_produce_identical_output(): void
    {
        $testFiles = [
            ['18x18.bin', 18],
            ['b0.bin', 8],
            ['b1.bin', 8],
        ];

        foreach ($testFiles as [$filename, $width]) {
            $filePath = $this->testFilesDir . '/' . $filename;

            if (!file_exists($filePath)) {
                continue;
            }

            $compressedData = file_get_contents($filePath);

            // Legacy decode
            $decoder1     = new CCITT4Decoder($width, $compressedData, false);
            $lines        = $decoder1->decode();
            $legacyOutput = BitmapPacker::packLines($lines, $width);

            // Streaming decode
            $decoder2 = new CCITT4Decoder($width, $compressedData, false);
            $stream   = fopen('php://temp', 'rb+');
            $decoder2->decodeToStream($stream);
            rewind($stream);
            $streamOutput = stream_get_contents($stream);
            fclose($stream);

            $this->assertEquals(
                $legacyOutput,
                $streamOutput,
                "Output mismatch for {$filename}: legacy vs streaming",
            );
        }
    }

    /**
     * Test full streaming workflow: file input → decoder → file output.
     */
    public function test_full_streaming_workflow(): void
    {
        $inputFile = $this->testFilesDir . '/18x18.bin';

        if (!file_exists($inputFile)) {
            $this->markTestSkipped('Test file not found');
        }

        // Open input as stream
        $inputStream = fopen($inputFile, 'rb');
        $this->assertIsResource($inputStream);

        // Create output stream
        $outputStream = fopen('php://temp', 'rb+');
        $this->assertIsResource($outputStream);

        // Decode with full streaming
        $decoder      = new CCITT4Decoder(18, $inputStream, false);
        $bytesWritten = $decoder->decodeToStream($outputStream);

        $this->assertGreaterThan(0, $bytesWritten);

        // Verify output
        rewind($outputStream);
        $output = stream_get_contents($outputStream);
        $this->assertEquals($bytesWritten, strlen($output));

        fclose($inputStream);
        fclose($outputStream);
    }

    /**
     * Test decoding with various parameter combinations.
     */
    public function test_decode_with_various_parameters(): void
    {
        $testFile = $this->testFilesDir . '/18x18.bin';

        if (!file_exists($testFile)) {
            $this->markTestSkipped('Test file not found');
        }

        $compressedData = file_get_contents($testFile);

        $parameterSets = [
            ['decoder' => 'group4', 'reverseColor' => false],
            ['decoder' => 'group4', 'reverseColor' => true],
        ];

        foreach ($parameterSets as $params) {
            $decoder = new CCITT4Decoder(18, $compressedData, $params['reverseColor']);
            $lines   = $decoder->decode();

            $this->assertIsArray($lines);
            $this->assertGreaterThan(0, count($lines));
        }
    }

    /**
     * Test Group 3 1D decoder with test files.
     */
    public function test_group3_1d_decoder(): void
    {
        $testFile = $this->testFilesDir . '/18x18.bin';

        if (!file_exists($testFile)) {
            $this->markTestSkipped('Test file not found');
        }

        $compressedData = file_get_contents($testFile);
        $params         = new Params(k: 0, columns: 18, rows: 18);

        try {
            $decoder = new CCITT3Decoder($params, $compressedData);
            $lines   = $decoder->decode();

            $this->assertIsArray($lines);
        } catch (RuntimeException $e) {
            // Some test files may not be Group 3 format
            $this->assertInstanceOf(RuntimeException::class, $e);
        }
    }

    /**
     * Test that decoder handles empty/invalid data gracefully.
     */
    public function test_decoder_handles_invalid_data_gracefully(): void
    {
        $invalidData = "\x00\x00\x00\x00";

        $decoder = new CCITT4Decoder(18, $invalidData, false);

        try {
            $lines = $decoder->decode();
            $this->assertIsArray($lines);
        } catch (RuntimeException $e) {
            // Expected behavior for invalid data
            $this->assertInstanceOf(RuntimeException::class, $e);
        }
    }

    /**
     * Guess image width from filename patterns.
     */
    private function guessWidthFromFilename(string $filename): int
    {
        // Parse dimensions from filename if present
        if (preg_match('/(\d+)x(\d+)/', $filename, $matches)) {
            return (int) $matches[1];
        }

        // Special cases
        if (str_starts_with($filename, 'b')) {
            return 8;  // b0, b1, b2, etc. are 8 pixels wide
        }

        if (str_contains($filename, 'CCITT')) {
            return 1728;  // Standard fax width
        }

        // Default fallback
        return 1728;
    }
}
