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

use function count;
use function dirname;
use function fclose;
use function file_exists;
use function filesize;
use function fopen;
use function fread;
use function gc_collect_cycles;
use function max;
use function memory_get_peak_usage;
use function memory_get_usage;
use function microtime;
use function range;
use function round;
use function strlen;
use function uniqid;
use function unlink;
use PXP\PDF\Fpdf\Features\Extractor\Text;
use PXP\PDF\Fpdf\Features\Splitter\PDFMerger;
use PXP\PDF\Fpdf\Features\Splitter\PDFSplitter;
use Test\TestCase;

/**
 * Efficient text extraction tests for large PDF files (23-grande.pdf - 16MB, 534 pages).
 * Tests focus on performance, memory efficiency, and buffer vs file extraction.
 *
 * Performance Benchmarks (measured on test runs):
 * - Full document (534 pages): ~0.2-0.5s, ~77K characters extracted
 * - Single page extraction: ~170-220ms
 * - Page range (5 pages): ~165-210ms
 * - Page count retrieval: ~165-180ms
 * - Small buffer extraction (2-3 pages, ~30MB): Comparable to file extraction (~40-160ms per page)
 * - Peak memory usage: ~37-60MB (file), small overhead for small page buffers
 * - Throughput: ~160K-350K+ characters/second
 *
 * This test validates that buffer extraction with small, complete PDF buffers
 * (2-3 pages) is efficient and performs comparably to file-based extraction
 * while allowing for better reuse when multiple operations are needed on
 * the same PDF section. Buffers are kept small (2-3 pages) to avoid memory
 * exhaustion and maintain reasonable performance.
 *
 * @covers \PXP\PDF\Fpdf\Extractor\Text
 */
final class LargeFileTextExtractionTest extends TestCase
{
    private const string TEST_PDF = 'tests/resources/PDF/input/23-grande.pdf';
    private string $pdfPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdfPath = $this->getProjectRoot() . '/' . self::TEST_PDF;

        if (!file_exists($this->pdfPath)) {
            $this->markTestSkipped('Test PDF not available: ' . $this->pdfPath);
        }
    }

    /**
     * Test efficient page count retrieval without full text extraction.
     */
    public function test_get_page_count_efficiently(): void
    {
        $text = new Text;

        $startTime   = microtime(true);
        $startMemory = memory_get_usage(true);

        $pageCount = $text->getPageCount($this->pdfPath);

        $duration     = microtime(true) - $startTime;
        $memoryUsed   = memory_get_usage(true) - $startMemory;
        $memoryUsedMB = $memoryUsed / 1024 / 1024;

        $this->assertGreaterThan(0, $pageCount, 'Should have pages');
        $this->assertLessThan(2.0, $duration, 'Page count should be retrieved quickly (< 2s)');
        $this->assertLessThan(50, $memoryUsedMB, 'Page count should use minimal memory (< 50MB)');

        self::getLogger()->info('Page count retrieval performance', [
            'page_count'  => $pageCount,
            'duration_ms' => (int) ($duration * 1000),
            'memory_mb'   => round($memoryUsedMB, 2),
        ]);
    }

    /**
     * Test extracting text from a single page efficiently (without loading entire document).
     */
    public function test_extract_single_page_efficiently(): void
    {
        $extractor = new Text;
        $pageNum   = 1; // First page

        $startTime   = microtime(true);
        $startMemory = memory_get_usage(true);

        $text = $extractor->extractFromFilePage($this->pdfPath, $pageNum);

        $duration     = microtime(true) - $startTime;
        $memoryUsed   = memory_get_usage(true) - $startMemory;
        $memoryUsedMB = $memoryUsed / 1024 / 1024;

        $this->assertIsString($text);
        $this->assertLessThan(5.0, $duration, 'Single page extraction should be fast (< 5s)');
        $this->assertLessThan(100, $memoryUsedMB, 'Single page should use reasonable memory (< 100MB)');

        self::getLogger()->info('Single page extraction performance', [
            'page'        => $pageNum,
            'text_length' => strlen($text),
            'duration_ms' => (int) ($duration * 1000),
            'memory_mb'   => round($memoryUsedMB, 2),
        ]);
    }

    /**
     * Test extracting a specific range of pages efficiently.
     */
    public function test_extract_page_range_efficiently(): void
    {
        $text      = new Text;
        $startPage = 1;
        $endPage   = 5; // First 5 pages

        $startTime   = microtime(true);
        $startMemory = memory_get_usage(true);

        $pages = $text->extractFromFilePages($this->pdfPath, $startPage, $endPage);

        $duration     = microtime(true) - $startTime;
        $memoryUsed   = memory_get_usage(true) - $startMemory;
        $memoryUsedMB = $memoryUsed / 1024 / 1024;

        $this->assertIsArray($pages);
        $this->assertCount(5, $pages, 'Should extract 5 pages');

        foreach (range($startPage, $endPage) as $pageNum) {
            $this->assertArrayHasKey($pageNum, $pages, "Should have page {$pageNum}");
            $this->assertIsString($pages[$pageNum]);
        }

        $this->assertLessThan(10.0, $duration, 'Page range extraction should be reasonable (< 10s)');
        $this->assertLessThan(150, $memoryUsedMB, 'Page range should use reasonable memory (< 150MB)');

        self::getLogger()->info('Page range extraction performance', [
            'start_page'  => $startPage,
            'end_page'    => $endPage,
            'pages_count' => count($pages),
            'duration_ms' => (int) ($duration * 1000),
            'memory_mb'   => round($memoryUsedMB, 2),
        ]);
    }

    /**
     * Test buffer extraction (from string) vs file extraction performance comparison.
     * This uses a small complete PDF (extracted pages) as buffer for realistic testing.
     */
    public function test_buffer_vs_file_extraction_performance(): void
    {
        $text = new Text;

        // Test 1: Extract from file (single page)
        gc_collect_cycles();
        $fileStartTime   = microtime(true);
        $fileStartMemory = memory_get_usage(true);

        $textFromFile = $text->extractFromFilePage($this->pdfPath, 1);

        $fileDuration   = microtime(true) - $fileStartTime;
        $fileMemoryUsed = memory_get_usage(true) - $fileStartMemory;
        $fileMemoryMB   = $fileMemoryUsed / 1024 / 1024;

        // Test 2: Extract from buffer using first 2 pages as a small, complete PDF buffer
        // Create a small PDF with just first 2 pages to use as buffer
        $tmpSmallPdf = self::getRootDir() . '/small_buffer_test_' . uniqid() . '.pdf';

        $pdfSplitter = new PDFSplitter($this->pdfPath, self::createFileIO(), self::getLogger());
        $pdfMerger   = new PDFMerger(self::createFileIO(), self::getLogger());

        // Extract first 2 pages only (small buffer)
        $tmpPages = [];

        for ($i = 1; $i <= 2; $i++) {
            $tmpPage = self::getRootDir() . '/small_buf_pg_' . $i . '_' . uniqid() . '.pdf';
            $pdfSplitter->extractPage($i, $tmpPage);
            $tmpPages[] = $tmpPage;
        }

        // Merge into small PDF
        $pdfMerger->mergeIncremental($tmpPages, $tmpSmallPdf);

        // Cleanup temp pages
        foreach ($tmpPages as $tmpPage) {
            @unlink($tmpPage);
        }

        // Now read this small complete PDF into buffer
        $handle = fopen($tmpSmallPdf, 'r');
        $this->assertNotFalse($handle, 'Should open small PDF file');
        $smallBuffer = fread($handle, filesize($tmpSmallPdf));
        fclose($handle);
        $bufferSizeMB = strlen($smallBuffer) / 1024 / 1024;

        gc_collect_cycles();
        $bufferStartTime   = microtime(true);
        $bufferStartMemory = memory_get_usage(true);

        $textFromBuffer = $text->extractFromStringPage($smallBuffer, 1);

        $bufferDuration   = microtime(true) - $bufferStartTime;
        $bufferMemoryUsed = memory_get_usage(true) - $bufferStartMemory;
        $bufferMemoryMB   = $bufferMemoryUsed / 1024 / 1024;

        // Cleanup
        @unlink($tmpSmallPdf);

        // Verify both methods produce the same result
        $this->assertSame($textFromFile, $textFromBuffer, 'Both methods should extract identical text');

        // Log performance comparison
        self::getLogger()->info('Buffer vs File extraction comparison (small buffer)', [
            'buffer_size_kb'     => round($bufferSizeMB * 1024, 2),
            'buffer_pages'       => 2,
            'file_duration_ms'   => (int) ($fileDuration * 1000),
            'file_memory_mb'     => round($fileMemoryMB, 2),
            'buffer_duration_ms' => (int) ($bufferDuration * 1000),
            'buffer_memory_mb'   => round($bufferMemoryMB, 2),
            'text_length'        => strlen($textFromFile),
        ]);

        // Buffer extraction should be reasonably efficient with small buffer
        $this->assertLessThan(10.0, $bufferDuration, 'Buffer extraction should complete in reasonable time');
        $this->assertLessThan(50, $bufferMemoryMB, 'Small buffer extraction should use minimal memory');
    }

    /**
     * Test full document text extraction with memory monitoring.
     * This test validates we can extract the entire large document without memory issues.
     */
    public function test_full_document_extraction_with_memory_monitoring(): void
    {
        $text = new Text;

        gc_collect_cycles();
        $startTime    = microtime(true);
        $startMemory  = memory_get_usage(true);
        $startPeakMem = memory_get_peak_usage(true);

        $fullText = $text->extractFromFile($this->pdfPath);

        $duration       = microtime(true) - $startTime;
        $memoryUsed     = memory_get_usage(true) - $startMemory;
        $peakMemoryUsed = memory_get_peak_usage(true) - $startPeakMem;
        $memoryUsedMB   = $memoryUsed / 1024 / 1024;
        $peakMemoryMB   = $peakMemoryUsed / 1024 / 1024;

        $this->assertIsString($fullText);
        $this->assertGreaterThan(0, strlen($fullText), 'Should extract some text from large document');

        // Performance expectations for 16MB PDF
        $this->assertLessThan(60.0, $duration, 'Full extraction should complete within 60 seconds');
        $this->assertLessThan(512, $peakMemoryMB, 'Peak memory should be reasonable (< 512MB)');

        self::getLogger()->info('Full document extraction performance', [
            'text_length'    => strlen($fullText),
            'duration_sec'   => round($duration, 2),
            'memory_used_mb' => round($memoryUsedMB, 2),
            'peak_memory_mb' => round($peakMemoryMB, 2),
            'chars_per_sec'  => (int) (strlen($fullText) / max($duration, 0.001)),
        ]);
    }

    /**
     * Test extraction from last pages (common use case for validation).
     */
    public function test_extract_last_pages_efficiently(): void
    {
        $text = new Text;

        // Get total pages
        $totalPages = $text->getPageCount($this->pdfPath);
        $this->assertGreaterThan(5, $totalPages, 'Should have more than 5 pages');

        // Extract last 3 pages
        $startPage = $totalPages - 2;
        $endPage   = $totalPages;

        $startTime = microtime(true);
        $pages     = $text->extractFromFilePages($this->pdfPath, $startPage, $endPage);
        $duration  = microtime(true) - $startTime;

        $this->assertCount(3, $pages, 'Should extract last 3 pages');
        $this->assertLessThan(10.0, $duration, 'Should extract last pages quickly');

        self::getLogger()->info('Last pages extraction', [
            'total_pages' => $totalPages,
            'start_page'  => $startPage,
            'end_page'    => $endPage,
            'duration_ms' => (int) ($duration * 1000),
        ]);
    }

    /**
     * Test that extracting invalid page numbers handles gracefully.
     */
    public function test_extract_invalid_page_returns_empty(): void
    {
        $extractor = new Text;

        // Page 0 (invalid)
        $text = $extractor->extractFromFilePage($this->pdfPath, 0);
        $this->assertSame('', $text, 'Invalid page 0 should return empty string');

        // Page beyond document
        $text = $extractor->extractFromFilePage($this->pdfPath, 999999);
        $this->assertSame('', $text, 'Page beyond document should return empty string');

        // Negative page
        $text = $extractor->extractFromFilePage($this->pdfPath, -1);
        $this->assertSame('', $text, 'Negative page should return empty string');
    }

    /**
     * Test buffer extraction for multiple pages (efficient batch processing).
     * Uses a small complete PDF (3 pages) to test realistic buffer reuse scenarios.
     */
    public function test_buffer_extraction_multiple_pages_efficient(): void
    {
        // Create a small complete PDF with first 3 pages for buffer testing
        $tmpSmallPdf = self::getRootDir() . '/small_multi_buffer_' . uniqid() . '.pdf';

        $pdfSplitter = new PDFSplitter($this->pdfPath, self::createFileIO(), self::getLogger());
        $pdfMerger   = new PDFMerger(self::createFileIO(), self::getLogger());

        // Extract first 3 pages only
        $tmpPages = [];

        for ($i = 1; $i <= 3; $i++) {
            $tmpPage = self::getRootDir() . '/multi_buf_pg_' . $i . '_' . uniqid() . '.pdf';
            $pdfSplitter->extractPage($i, $tmpPage);
            $tmpPages[] = $tmpPage;
        }

        // Merge into small PDF
        $pdfMerger->mergeIncremental($tmpPages, $tmpSmallPdf);

        // Cleanup temp pages
        foreach ($tmpPages as $tmpPage) {
            @unlink($tmpPage);
        }

        // Load small complete PDF into buffer
        $handle = fopen($tmpSmallPdf, 'r');
        $this->assertNotFalse($handle, 'Should open small PDF file');
        $smallBuffer = fread($handle, filesize($tmpSmallPdf));
        fclose($handle);
        $bufferSizeMB = strlen($smallBuffer) / 1024 / 1024;

        $text = new Text;

        // Extract different page ranges from the same small buffer
        // This simulates efficient reuse of loaded buffer
        $startTime = microtime(true);

        $page1 = $text->extractFromStringPage($smallBuffer, 1);
        $page2 = $text->extractFromStringPage($smallBuffer, 2);
        $page3 = $text->extractFromStringPage($smallBuffer, 3);

        $duration = microtime(true) - $startTime;

        // Cleanup
        @unlink($tmpSmallPdf);

        $this->assertIsString($page1);
        $this->assertIsString($page2);
        $this->assertIsString($page3);

        // Multiple extractions from buffer should be efficient
        $this->assertLessThan(15.0, $duration, 'Multiple buffer extractions should be efficient');

        self::getLogger()->info('Multiple buffer extractions performance (small buffer)', [
            'buffer_size_mb'    => round($bufferSizeMB, 2),
            'buffer_pages'      => 3,
            'extractions_count' => 3,
            'duration_ms'       => (int) ($duration * 1000),
            'avg_per_page_ms'   => (int) (($duration / 3) * 1000),
        ]);
    }

    /**
     * Get project root directory.
     */
    private function getProjectRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
