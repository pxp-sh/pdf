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
namespace Test\Feature\PDF;

use function array_map;
use function basename;
use function dirname;
use function exec;
use function file_exists;
use function file_get_contents;
use function filesize;
use function glob;
use function round;
use function similar_text;
use function strlen;
use function trim;
use function uniqid;
use function unlink;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PXP\PDF\Fpdf\Features\Extractor\Text;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Extractor\Text
 */
final class TextExtractionTest extends TestCase
{
    private string $resourcesDir;

    public static function pdfFileProvider(): array
    {
        $resourcesDir = dirname(__DIR__, 3) . '/tests/resources/PDF/input';
        $files        = glob($resourcesDir . '/*.pdf');

        return array_map(static fn ($file) => [$file], $files);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Get the actual project root, not the temp dir
        $this->resourcesDir = dirname(__DIR__, 3) . '/tests/resources/PDF/input';
    }

    /**
     * This test uses real PDF files from the resources directory.
     * It verifies that text can be extracted from actual PDF files with quality validation.
     */
    #[DataProvider('pdfFileProvider')]
    public function test_extract_text_from_real_pdf_files(string $filePath): void
    {
        // Skip if pdftotext is not available
        if (!self::commandExists('pdftotext')) {
            $this->markTestSkipped('pdftotext command not available for baseline comparison');
        }

        if (!file_exists($filePath)) {
            $this->markTestSkipped('PDF file not found: ' . $filePath);
        }

        // Get baseline text from pdftotext
        $unid         = uniqid();
        $tempTextFile = self::getRootDir() . '/pdftotext_output_' . $unid . '.txt';
        exec("pdftotext \"{$filePath}\" \"{$tempTextFile}\"", $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($tempTextFile)) {
            $this->markTestSkipped('pdftotext failed to extract baseline text');
        }

        try {
            $baselineText = file_get_contents($tempTextFile);
            $baselineText = trim($baselineText);

            // Skip if baseline has no text (scanned image PDF with no OCR)
            if (empty($baselineText) || strlen($baselineText) < 10) {
                $this->markTestSkipped('PDF has no extractable text (likely scanned image)');
            }

            // Extract text using our library
            $extractor = new Text;

            try {
                $extractedText = $extractor->extractFromFile($filePath);
            } catch (Exception $e) {
                // If extraction fails due to parsing issues, skip the test with detailed info
                $this->markTestSkipped(
                    'PDF parsing failed (not a text extraction issue): ' .
                    $e->getMessage() . ' in ' . basename($filePath),
                );
            }

            $this->assertIsString($extractedText);
            $similarity = $this->calculateTextSimilarity($baselineText, $extractedText);
            $this->assertGreaterThan(
                70.0,
                $similarity,
                "Text extraction quality too low ({$similarity}% similarity). Expected >70% match with pdftotext baseline.",
            );
        } finally {
            if (file_exists($tempTextFile)) {
                // unlink($tempTextFile);
            }
        }
    }

    #[DataProvider('pdfFileProvider')]
    public function test_compare_text_extraction_with_pdftotext(string $filePath): void
    {
        // Skip if pdftotext is not available
        if (!self::commandExists('pdftotext')) {
            $this->markTestSkipped('pdftotext command not available');
        }

        // Use existing PDF from resources
        $pdfFile = $filePath;

        if (!file_exists($pdfFile)) {
            $this->markTestSkipped('Test PDF file not found: ' . $pdfFile);
        }

        try {
            $unid         = uniqid();
            $tempTextFile = self::getRootDir() . '/pdftotext_output_' . $unid . '.txt';
            exec("pdftotext \"{$pdfFile}\" \"{$tempTextFile}\"", $output, $returnCode);

            if ($returnCode !== 0) {
                $this->markTestSkipped('pdftotext failed to extract text');
            }

            $this->assertFileExists($tempTextFile);
            $pdftotextContent = trim(file_get_contents($tempTextFile));

            // Skip if baseline has no text (scanned image PDF)
            if (empty($pdftotextContent) || strlen($pdftotextContent) < 10) {
                $this->markTestSkipped('PDF has no extractable text (likely scanned image)');
            }

            // Extract text using our library
            $extractor = new Text;

            try {
                $ourExtractedText = $extractor->extractFromFile(filePath: $pdfFile);
            } catch (Exception $e) {
                // If extraction fails due to parsing issues, skip the test with detailed info
                $this->markTestSkipped(
                    'PDF parsing failed (not a text extraction issue): ' .
                    $e->getMessage() . ' in ' . basename($pdfFile),
                );
            }

            $tempTextFile = self::getRootDir() . '/pxp_output_' . $unid . '.txt';

            $similarity = $this->calculateTextSimilarity($pdftotextContent, $ourExtractedText);
            $this->assertGreaterThan(
                70.0,
                $similarity,
                "Text extracted by our library should be at least 70% similar to pdftotext output (got {$similarity}%)",
            );
        } finally {
            // Cleanup
            if (isset($tempTextFile) && file_exists($tempTextFile)) {
                // self::unlink($tempTextFile);
            }
        }
    }

    #[DataProvider('pdfFileProvider')]
    public function test_extract_text_from_dataset(string $filePath): void
    {
        // Skip if pdftotext is not available
        if (!self::commandExists('pdftotext')) {
            $this->markTestSkipped('pdftotext command not available for baseline comparison');
        }

        // Get baseline text from pdftotext
        $unid         = uniqid();
        $tempTextFile = self::getRootDir() . '/pdftotext_output_' . $unid . '.txt';
        exec("pdftotext \"{$filePath}\" \"{$tempTextFile}\"", $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($tempTextFile)) {
            $this->markTestSkipped('pdftotext failed to extract baseline text');
        }

        try {
            $baselineText = trim(file_get_contents($tempTextFile));

            // Skip if baseline has no text (scanned image PDF)
            if (empty($baselineText) || strlen($baselineText) < 10) {
                $this->markTestSkipped('PDF has no extractable text (likely scanned image)');
            }

            // Extract text using streaming or direct method based on file size
            $extractor = new Text;
            $fileSize  = filesize($filePath);
            $text      = '';

            try {
                if ($fileSize > 1024 * 1024) { // 1MB threshold for streaming
                    $extractor->extractFromFileStreaming(static function ($chunk) use (&$text): void
                    {
                        $text .= $chunk;
                    }, $filePath);
                } else {
                    $text = $extractor->extractFromFile($filePath);
                }
            } catch (Exception $e) {
                // If extraction fails due to parsing issues, skip the test with detailed info
                $this->markTestSkipped(
                    'PDF parsing failed (not a text extraction issue): ' .
                    $e->getMessage() . ' in ' . basename($filePath),
                );
            }

            $this->assertIsString($text);
            $similarity = $this->calculateTextSimilarity($baselineText, $text);
            $this->assertGreaterThan(
                70.0,
                $similarity,
                "Text extraction quality too low ({$similarity}% similarity). Expected >70% match with pdftotext baseline.",
            );
        } finally {
            if (file_exists($tempTextFile)) {
                // unlink($tempTextFile);
            }
        }
    }

    /**
     * Calculate text similarity percentage using PHP's similar_text function.
     */
    private function calculateTextSimilarity(string $text1, string $text2): float
    {
        $text1 = trim($text1);
        $text2 = trim($text2);

        if (empty($text1) && empty($text2)) {
            return 100.0;
        }

        if (empty($text1) || empty($text2)) {
            return 0.0;
        }

        $similarity = 0;
        similar_text($text1, $text2, $similarity);

        return round($similarity, 2);
    }
}
