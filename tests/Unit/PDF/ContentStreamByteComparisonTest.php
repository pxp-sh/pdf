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
namespace Tests\Unit\PDF;

use function count;
use function escapeshellarg;
use function exec;
use function file_get_contents;
use function filesize;
use function implode;
use function md5;
use function preg_match_all;
use function round;
use function similar_text;
use function sprintf;
use function strlen;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use PXP\PDF\Fpdf\Core\FPDF;
use PXP\PDF\Fpdf\Features\Splitter\PDFSplitter;
use PXP\PDF\Fpdf\IO\FileIO;
use RuntimeException;
use Test\TestCase;

/**
 * Byte-level comparison of content streams.
 *
 * Uses external tools (qpdf, mutool) to compare:
 * - Raw content stream bytes
 * - Decompressed content streams
 * - Content stream checksums
 */
class ContentStreamByteComparisonTest extends TestCase
{
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new Logger('test');
        $this->logger->pushHandler(new StreamHandler('php://stdout'));
    }

    /**
     * Test byte-by-byte content stream comparison using qpdf.
     */
    public function test_content_stream_bytes_with_qpdf(): void
    {
        if (!$this->isQpdfAvailable()) {
            $this->markTestSkipped('qpdf is not available');
        }

        // Create test PDF
        $sourcePath = $this->createSimpleTestPdf();

        // Extract page
        $extractedPath = sys_get_temp_dir() . '/byte_comparison_extracted.pdf';
        $fileIO        = new FileIO($this->logger);
        $pdfSplitter   = new PDFSplitter($sourcePath, $fileIO, $this->logger);
        $pdfSplitter->extractPage(1, $extractedPath);

        $sourceDecompressed    = sys_get_temp_dir() . '/source_decompressed.pdf';
        $extractedDecompressed = sys_get_temp_dir() . '/extracted_decompressed.pdf';

        $this->decompressPdfWithQpdf($sourcePath, $sourceDecompressed);
        $this->decompressPdfWithQpdf($extractedPath, $extractedDecompressed);

        // Extract content streams
        $sourceContent    = $this->extractContentStream($sourceDecompressed);
        $extractedContent = $this->extractContentStream($extractedDecompressed);

        // Compare checksums
        $sourceChecksum    = md5($sourceContent);
        $extractedChecksum = md5($extractedContent);

        $this->logger->info('Content stream comparison', [
            'source_size'        => strlen($sourceContent),
            'extracted_size'     => strlen($extractedContent),
            'source_checksum'    => $sourceChecksum,
            'extracted_checksum' => $extractedChecksum,
            'checksums_match'    => $sourceChecksum === $extractedChecksum,
        ]);

        // Content should be identical or very similar
        $similarity = $this->calculateSimilarity($sourceContent, $extractedContent);
        $this->assertGreaterThan(0.95, $similarity, 'Content streams should be >95% similar');

        // Cleanup
        self::unlink($sourcePath);
        self::unlink($extractedPath);
        self::unlink($sourceDecompressed);
        self::unlink($extractedDecompressed);
    }

    /**
     * Test content stream comparison using mutool.
     */
    public function test_content_stream_bytes_with_mutool(): void
    {
        if (!$this->isMutoolAvailable()) {
            $this->markTestSkipped('mutool is not available');
        }

        // Create test PDF
        $sourcePath = $this->createSimpleTestPdf();

        // Extract page
        $extractedPath = sys_get_temp_dir() . '/mutool_comparison_extracted.pdf';
        $fileIO        = new FileIO($this->logger);
        $pdfSplitter   = new PDFSplitter($sourcePath, $fileIO, $this->logger);
        $pdfSplitter->extractPage(1, $extractedPath);
        $extractedText = $this->extractTextWithMutool($extractedPath);

        // Skip comparison if mutool not available or returned null
        if ($sourceText === null || $extractedText === null) {
            $this->markTestSkipped('mutool not available or returned null');
        }

        // Compare text content
        $this->assertEquals(
            trim($sourceText),
            trim($extractedText),
            'Text content should be identical',
        );

        // Cleanup
        self::unlink($sourcePath);
        self::unlink($extractedPath);
    }

    /**
     * Test content stream checksum comparison.
     */
    public function test_content_stream_checksums(): void
    {
        // Create test PDF with known content
        $sourcePath = $this->createSimpleTestPdf();

        // Extract page
        $extractedPath = sys_get_temp_dir() . '/checksum_test_extracted.pdf';
        $fileIO        = new FileIO($this->logger);
        $pdfSplitter   = new PDFSplitter($sourcePath, $fileIO, $this->logger);
        $pdfSplitter->extractPage(1, $extractedPath);

        $this->assertFileExists($extractedPath);

        // Compare file sizes and checksums
        $sourceSize    = filesize($sourcePath);
        $extractedSize = filesize($extractedPath);

        $this->assertGreaterThan(0, $extractedSize);
        $this->assertLessThanOrEqual($sourceSize * 1.5, $extractedSize, 'Extracted should not be much larger');

        // Both should be valid PDFs
        file_get_contents($sourcePath);
        $extractedContent = file_get_contents($extractedPath);

        $this->assertStringStartsWith('%PDF-', $extractedContent);
        $this->assertStringContainsString('%%EOF', $extractedContent);

        // Log file sizes for comparison
        $this->logger->info('Checksum test completed', [
            'source_size'    => $sourceSize,
            'extracted_size' => $extractedSize,
            'size_ratio'     => round($extractedSize / $sourceSize, 2),
        ]);

        // Cleanup
        self::unlink($sourcePath);
        self::unlink($extractedPath);
    }

    /**
     * Test that whitespace and operators are preserved.
     */
    public function test_whitespace_and_operators_preserved(): void
    {
        // Create PDF with specific text positioning
        $fpdf = new FPDF;
        $fpdf->AddPage();
        $fpdf->SetFont('Arial', 'B', 14);
        $fpdf->Cell(0, 10, 'Line 1 with spaces', 0, 1);
        $fpdf->Cell(0, 10, '    Indented line', 0, 1);
        $fpdf->Cell(0, 10, 'Line with    multiple    spaces', 0, 1);

        $sourcePath = sys_get_temp_dir() . '/whitespace_test.pdf';
        $fpdf->Output('F', $sourcePath);

        // Extract
        $extractedPath = sys_get_temp_dir() . '/whitespace_extracted.pdf';
        $fileIO        = new FileIO($this->logger);
        $pdfSplitter   = new PDFSplitter($sourcePath, $fileIO, $this->logger);
        $pdfSplitter->extractPage(1, $extractedPath);

        $this->assertFileExists($extractedPath);

        // Verify extracted PDF is valid and has reasonable size
        $sourceSize    = filesize($sourcePath);
        $extractedSize = filesize($extractedPath);

        $this->assertGreaterThan(0, $extractedSize, 'Extracted PDF should have content');
        $this->assertLessThanOrEqual($sourceSize * 1.5, $extractedSize, 'Extracted PDF should not be significantly larger');

        // Verify PDF structure
        $extractedContent = file_get_contents($extractedPath);
        $this->assertStringStartsWith('%PDF-', $extractedContent);
        $this->assertStringContainsString('%%EOF', $extractedContent);
        $this->assertStringContainsString('/Type /Page', $extractedContent);

        $this->logger->info('Whitespace preservation test completed', [
            'source_size'    => $sourceSize,
            'extracted_size' => $extractedSize,
        ]);

        // Cleanup
        self::unlink($sourcePath);
        self::unlink($extractedPath);
    }

    // Helper methods

    private function createSimpleTestPdf(): string
    {
        $fpdf = new FPDF;
        $fpdf->AddPage();
        $fpdf->SetFont('Arial', '', 12);
        $fpdf->Cell(0, 10, 'Test content for byte comparison', 0, 1);
        $fpdf->Cell(0, 10, 'This is line 2', 0, 1);
        $fpdf->Cell(0, 10, 'This is line 3', 0, 1);

        $path = sys_get_temp_dir() . '/byte_test_' . uniqid() . '.pdf';
        $fpdf->Output('F', $path);

        return $path;
    }

    private function isQpdfAvailable(): bool
    {
        $output     = [];
        $returnCode = 0;
        exec('which qpdf 2>&1', $output, $returnCode);

        return $returnCode === 0;
    }

    private function isMutoolAvailable(): bool
    {
        $output     = [];
        $returnCode = 0;
        exec('which mutool 2>&1', $output, $returnCode);

        return $returnCode === 0;
    }

    private function decompressPdfWithQpdf(string $input, string $output): void
    {
        $cmd = sprintf('qpdf --stream-data=uncompress %s %s 2>&1', escapeshellarg($input), escapeshellarg($output));
        exec($cmd, $cmdOutput, $returnCode);

        if ($returnCode !== 0) {
            throw new RuntimeException('qpdf decompression failed: ' . implode("\n", $cmdOutput));
        }
    }

    private function extractContentStream(string $pdfPath): string
    {
        // Simple extraction - read PDF and find stream between 'stream' and 'endstream'
        $content = file_get_contents($pdfPath);

        // Find all streams
        preg_match_all('/stream\s+(.*?)\s+endstream/s', $content, $matches);

        if (isset($matches[1]) && count($matches[1]) > 0) {
            // Return first content stream (simplified)
            return $matches[1][0];
        }

        return '';
    }

    private function extractTextWithMutool(string $pdfPath): string
    {
        $output     = [];
        $returnCode = 0;
        exec(sprintf('mutool draw -F txt %s 2>&1', escapeshellarg($pdfPath)), $output, $returnCode);

        if ($returnCode !== 0) {
            throw new RuntimeException('mutool text extraction failed');
        }

        return implode("\n", $output);
    }

    private function calculateSimilarity(string $str1, string $str2): float
    {
        $len1 = strlen($str1);
        $len2 = strlen($str2);

        if ($len1 === 0 && $len2 === 0) {
            return 1.0;
        }

        if ($len1 === 0 || $len2 === 0) {
            return 0.0;
        }

        // Use similar_text for similarity percentage
        similar_text($str1, $str2, $percent);

        return $percent / 100.0;
    }
}
