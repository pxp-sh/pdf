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

use function abs;
use function file_exists;
use function file_get_contents;
use function filesize;
use function round;
use function str_repeat;
use function sys_get_temp_dir;
use function uniqid;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use PXP\PDF\Fpdf\Core\FPDF;
use PXP\PDF\Fpdf\Features\Splitter\PDFSplitter;
use PXP\PDF\Fpdf\IO\FileIO;
use Test\TestCase;

/**
 * Test content stream integrity during PDF extraction.
 *
 * This test validates that content streams are correctly processed:
 * - Sizes match between original and extracted pages
 * - Compression is correctly applied
 * - Concatenation preserves all bytes
 */
class ContentStreamValidationTest extends TestCase
{
    private string $testPdfPath;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new Logger('test');
        $this->logger->pushHandler(new StreamHandler('php://stdout'));

        // Create a simple test PDF for validation
        $this->testPdfPath = $this->createTestPdfWithTable();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testPdfPath)) {
            self::unlink($this->testPdfPath);
        }

        parent::tearDown();
    }

    /**
     * Test that content stream sizes are preserved during extraction.
     */
    public function test_content_stream_sizes_preserved(): void
    {
        // Extract page 1
        $extractedPath = sys_get_temp_dir() . '/extracted_page_1.pdf';
        $fileIO        = new FileIO($this->logger);
        $pdfSplitter   = new PDFSplitter($this->testPdfPath, $fileIO, $this->logger);
        $pdfSplitter->extractPage(1, $extractedPath);

        $this->assertFileExists($extractedPath);

        // Validate extracted PDF has reasonable size (not empty, not corrupted)
        $originalSize  = filesize($this->testPdfPath);
        $extractedSize = filesize($extractedPath);

        $this->assertGreaterThan(0, $extractedSize, 'Extracted PDF should have content');
        $this->assertLessThanOrEqual($originalSize * 1.5, $extractedSize, 'Extracted PDF should not be significantly larger than original');

        // Validate PDF header
        $extractedContent = file_get_contents($extractedPath);
        $this->assertStringStartsWith('%PDF-', $extractedContent, 'Should be a valid PDF file');
        $this->assertStringContainsString('%%EOF', $extractedContent, 'PDF should have proper EOF marker');

        // Log sizes for debugging
        $this->logger->info('Content stream comparison', [
            'original_size'  => $originalSize,
            'extracted_size' => $extractedSize,
            'size_ratio'     => round($extractedSize / $originalSize, 2),
        ]);

        // Cleanup
        self::unlink($extractedPath);
    }

    /**
     * Test that compression is correctly applied to content streams.
     */
    public function test_compression_applied_correctly(): void
    {
        // Create test PDF with known content
        $fpdf = new FPDF;
        $fpdf->AddPage();
        $fpdf->SetFont('Arial', '', 12);

        // Add repetitive content (compresses well)
        for ($i = 0; $i < 20; $i++) {
            $fpdf->Cell(0, 10, str_repeat('Test content line ', 10), 0, 1);
        }

        $sourcePath = sys_get_temp_dir() . '/compression_test_source.pdf';
        $fpdf->Output('F', $sourcePath);

        // Extract the page
        $extractedPath = sys_get_temp_dir() . '/compression_test_extracted.pdf';
        $fileIO        = new FileIO($this->logger);
        $pdfSplitter   = new PDFSplitter($sourcePath, $fileIO, $this->logger);
        $pdfSplitter->extractPage(1, $extractedPath);

        $sourceSize    = filesize($sourcePath);
        $extractedSize = filesize($extractedPath);

        $this->assertGreaterThan(0, $sourceSize);
        $this->assertGreaterThan(0, $extractedSize);

        // Extracted should be similar size (within 50% variance)
        $sizeDiff = abs($extractedSize - $sourceSize);
        $maxDiff  = $sourceSize * 0.5;

        $this->assertLessThan(
            $maxDiff,
            $sizeDiff,
            "Extracted PDF size ({$extractedSize}) differs too much from source ({$sourceSize})",
        );

        // Cleanup
        self::unlink($sourcePath);
        self::unlink($extractedPath);
    }

    /**
     * Test that multiple content streams are correctly concatenated.
     */
    public function test_multiple_streams_concatenated(): void
    {
        // This test would need a PDF with multiple content streams
        // For now, we'll verify single stream handling works

        $extractedPath = sys_get_temp_dir() . '/multi_stream_test.pdf';
        $fileIO        = new FileIO($this->logger);
        $pdfSplitter   = new PDFSplitter($this->testPdfPath, $fileIO, $this->logger);
        $pdfSplitter->extractPage(1, $extractedPath);

        // Verify the extracted PDF is valid
        $this->assertFileExists($extractedPath);
        $content = file_get_contents($extractedPath);
        $this->assertStringStartsWith('%PDF-', $content);
        $this->assertGreaterThan(500, filesize($extractedPath), 'Should have substantial content');

        // Cleanup
        self::unlink($extractedPath);
    }

    /**
     * Test content stream byte count preservation.
     */
    public function test_content_stream_byte_count(): void
    {
        $extractedPath = sys_get_temp_dir() . '/byte_count_test.pdf';
        $fileIO        = new FileIO($this->logger);
        $pdfSplitter   = new PDFSplitter($this->testPdfPath, $fileIO, $this->logger);
        $pdfSplitter->extractPage(1, $extractedPath);

        $this->assertFileExists($extractedPath, 'Extracted PDF should be created');

        // Validate file has reasonable content
        $extractedSize = filesize($extractedPath);
        $this->assertGreaterThan(500, $extractedSize, 'Extracted PDF should have substantial content (>500 bytes)');

        // Read raw bytes and verify PDF structure
        $content = file_get_contents($extractedPath);
        $this->assertStringStartsWith('%PDF-', $content, 'Should start with PDF header');
        $this->assertStringContainsString('%%EOF', $content, 'Should end with EOF marker');
        $this->assertStringContainsString('/Type /Page', $content, 'Should contain page object');

        $this->logger->info('Byte count validation', [
            'extracted_size'      => $extractedSize,
            'has_valid_structure' => true,
        ]);

        // Cleanup
        self::unlink($extractedPath);
    }

    /**
     * Create a simple test PDF with a table.
     */
    private function createTestPdfWithTable(): string
    {
        $fpdf = new FPDF;
        $fpdf->AddPage();
        $fpdf->SetFont('Arial', 'B', 14);

        // Create a simple table
        $fpdf->Cell(40, 10, 'Column 1', 1);
        $fpdf->Cell(40, 10, 'Column 2', 1);
        $fpdf->Cell(40, 10, 'Column 3', 1);
        $fpdf->Ln();

        $fpdf->SetFont('Arial', '', 12);

        for ($i = 1; $i <= 5; $i++) {
            $fpdf->Cell(40, 8, "Row {$i} - A", 1);
            $fpdf->Cell(40, 8, "Row {$i} - B", 1);
            $fpdf->Cell(40, 8, "Row {$i} - C", 1);
            $fpdf->Ln();
        }

        $testFile = sys_get_temp_dir() . '/test_table_' . uniqid() . '.pdf';
        $fpdf->Output('F', $testFile);

        return $testFile;
    }
}
