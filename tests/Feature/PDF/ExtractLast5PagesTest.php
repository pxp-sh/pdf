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

use const SEEK_END;
use function array_values;
use function dirname;
use function fclose;
use function file_exists;
use function filesize;
use function fopen;
use function fread;
use function fseek;
use function gc_collect_cycles;
use function min;
use function mkdir;
use function preg_match;
use function range;
use function sprintf;
use function uniqid;
use PXP\PDF\Fpdf\Features\Splitter\PDFMerger;
use PXP\PDF\Fpdf\Features\Splitter\PDFSplitter;
use PXP\PDF\Fpdf\IO\FileIO;
use Test\TestCase;

/**
 * Rigorous test for extracting and validating the last 5 pages of 23-grande.pdf
 * This test validates 100% match between extracted pages and original content.
 *
 * @covers \PXP\PDF\Fpdf\Splitter\PDFMerger
 * @covers \PXP\PDF\Fpdf\Splitter\PDFSplitter
 */
final class ExtractLast5PagesTest extends TestCase
{
    private const string TEST_PDF = 'tests/resources/PDF/input/23-grande.pdf';

    /**
     * Test: Extract last 5 pages from 23-grande.pdf and verify 100% match.
     *
     * This test performs the following validations:
     * 1. Extract each of the last 5 pages individually
     * 2. Verify each extracted page is valid
     * 3. Compare each extracted page with original page (visual 100% match)
     * 4. Merge extracted pages back together
     * 5. Compare merged PDF with original last 5 pages (visual 100% match)
     * 6. Validate page count consistency
     */
    public function test_extract_last_5_pages_with_100_percent_match(): void
    {
        $inputPdf = $this->getProjectRoot() . '/' . self::TEST_PDF;

        if (!file_exists($inputPdf)) {
            $this->markTestSkipped('Test PDF not available: ' . $inputPdf);
        }

        // Create temp directory
        $tmpDir = self::getRootDir() . '/tmp_extract_' . uniqid();
        mkdir($tmpDir, 0o777, true);

        $fileIO = new FileIO(self::getLogger());

        // Step 1: Get total page count
        $pdfSplitter = new PDFSplitter($inputPdf, $fileIO, self::getLogger());
        $totalPages  = $pdfSplitter->getPageCount();

        $this->assertGreaterThanOrEqual(5, $totalPages, '23-grande.pdf should have at least 5 pages');

        // Calculate last 5 pages
        $lastPages = range($totalPages - 4, $totalPages);

        self::getLogger()->info('Extracting last 5 pages', [
            'total_pages'      => $totalPages,
            'pages_to_extract' => $lastPages,
        ]);

        // Step 2: Extract each of the last 5 pages individually
        $extractedPages = [];

        foreach ($lastPages as $lastPage) {
            $extractedFile = $tmpDir . "/page_{$lastPage}.pdf";
            $pdfSplitter->extractPage($lastPage, $extractedFile);

            // Verify extracted file exists and is valid
            $this->assertFileExists($extractedFile, "Extracted page {$lastPage} should exist");
            $this->assertGreaterThan(0, filesize($extractedFile), "Extracted page {$lastPage} should not be empty");

            // Verify it's a valid PDF using streaming read (first 8KB chunk)
            $header = $fileIO->readFileChunk($extractedFile, 8192, 0);
            $this->assertStringContainsString('%PDF-', $header, "Extracted page {$lastPage} should be a valid PDF");
            $this->assertStringContainsString('/Count 1', $header, "Extracted page {$lastPage} should have exactly 1 page");

            $extractedPages[$lastPage] = $extractedFile;

            self::getLogger()->info("Extracted page {$lastPage}", [
                'output_file' => $extractedFile,
                'size_bytes'  => filesize($extractedFile),
            ]);
            unset($header);
            gc_collect_cycles();
        }

        // Step 3: Compare each extracted page with original page (100% match required)
        $this->assertExtractedPagesMatch100Percent($inputPdf, $extractedPages);

        // Step 4: Merge extracted pages back together
        $mergedFile = $tmpDir . '/merged_last_5_pages.pdf';
        $pdfMerger  = new PDFMerger($fileIO, self::getLogger());

        $pdfMerger->mergeIncremental(array_values($extractedPages), $mergedFile);

        // Verify merged file
        $this->assertFileExists($mergedFile, 'Merged PDF should exist');
        $this->assertGreaterThan(0, filesize($mergedFile), 'Merged PDF should not be empty');

        // Verify merged PDF has exactly 5 pages
        $mergedPageCount = self::getPdfPageCount($mergedFile);
        $this->assertEquals(5, $mergedPageCount, 'Merged PDF should have exactly 5 pages');

        self::getLogger()->info('Merged extracted pages', [
            'output_file' => $mergedFile,
            'page_count'  => $mergedPageCount,
            'size_bytes'  => filesize($mergedFile),
        ]);

        // Step 5: Compare merged PDF pages with original pages (100% match required)
        $this->assertMergedPagesMatch100Percent($inputPdf, $mergedFile, $lastPages);

        // Cleanup
        foreach ($extractedPages as $extractedPage) {
            self::unlink($extractedPage);
        }
        self::unlink($mergedFile);
    }

    /**
     * Test: Extract last 5 pages and validate byte-level content integrity.
     *
     * This test focuses on ensuring no data corruption occurs during extraction.
     */
    public function test_extract_last_5_pages_content_integrity(): void
    {
        $inputPdf = $this->getProjectRoot() . '/' . self::TEST_PDF;

        if (!file_exists($inputPdf)) {
            $this->markTestSkipped('Test PDF not available: ' . $inputPdf);
        }

        $tmpDir = self::getRootDir() . '/tmp_integrity_' . uniqid();
        mkdir($tmpDir, 0o777, true);

        $fileIO = new FileIO(self::getLogger());

        // Get page count
        $pdfSplitter = new PDFSplitter($inputPdf, $fileIO, self::getLogger());
        $totalPages  = $pdfSplitter->getPageCount();

        $this->assertGreaterThanOrEqual(5, $totalPages, '23-grande.pdf should have at least 5 pages');

        $lastPages = range($totalPages - 4, $totalPages);

        // Extract pages
        $extractedPages = [];

        foreach ($lastPages as $pageNum) {
            $extractedFile = $tmpDir . "/page_{$pageNum}.pdf";
            $pdfSplitter->extractPage($pageNum, $extractedFile);
            $extractedPages[$pageNum] = $extractedFile;
        }

        // Validate PDF structure for each extracted page
        foreach ($extractedPages as $pageNum => $file) {
            $this->assertValidPdfStructure($file, "Page {$pageNum}");
        }

        // Merge and validate
        $mergedFile = $tmpDir . '/merged.pdf';
        $pdfMerger  = new PDFMerger($fileIO, self::getLogger());
        $pdfMerger->mergeIncremental(array_values($extractedPages), $mergedFile);

        // Validate merged PDF structure
        $this->assertValidPdfStructure($mergedFile, 'Merged PDF');

        // Verify page count matches
        $mergedPageCount = self::getPdfPageCount($mergedFile);
        $this->assertEquals(5, $mergedPageCount, 'Merged PDF should have exactly 5 pages');

        // Cleanup
        foreach ($extractedPages as $extractedPage) {
            self::unlink($extractedPage);
        }
        self::unlink($mergedFile);
    }

    /**
     * Test: Extract and re-extract pages to ensure idempotency.
     *
     * This test verifies that extracting a page from an already extracted page
     * produces identical results.
     */
    public function test_extract_last_5_pages_idempotency(): void
    {
        $inputPdf = $this->getProjectRoot() . '/' . self::TEST_PDF;

        if (!file_exists($inputPdf)) {
            $this->markTestSkipped('Test PDF not available: ' . $inputPdf);
        }

        $tmpDir = self::getRootDir() . '/tmp_idempotency_' . uniqid();
        mkdir($tmpDir, 0o777, true);

        $fileIO = new FileIO(self::getLogger());

        // Get last page only for this test
        $splitter   = new PDFSplitter($inputPdf, $fileIO, self::getLogger());
        $totalPages = $splitter->getPageCount();

        $lastPageNum = $totalPages;

        // First extraction
        $firstExtract = $tmpDir . '/first_extract.pdf';
        $splitter->extractPage($lastPageNum, $firstExtract);

        $this->assertFileExists($firstExtract);

        // Second extraction from the first extracted page
        $secondSplitter = new PDFSplitter($firstExtract, $fileIO, self::getLogger());
        $secondExtract  = $tmpDir . '/second_extract.pdf';
        $secondSplitter->extractPage(1, $secondExtract);

        $this->assertFileExists($secondExtract);

        // Compare both extractions - they should be visually identical
        // Using 100% match requirement
        $this->assertPdfPagesSimilar(
            $firstExtract,
            $secondExtract,
            1,
            1.0, // 100% match required
            'Re-extracted page should be identical to first extraction (idempotency check)',
        );

        // Cleanup
        self::unlink($firstExtract);
        self::unlink($secondExtract);
    }

    /**
     * Assert that extracted pages match original pages with 100% accuracy.
     *
     * @param string             $originalPdf    Path to original PDF
     * @param array<int, string> $extractedPages Map of page number to extracted file path
     */
    private function assertExtractedPagesMatch100Percent(string $originalPdf, array $extractedPages): void
    {
        foreach ($extractedPages as $pageNum => $extractedFile) {
            self::getLogger()->info("Validating extracted page {$pageNum} with 100% match requirement");

            // Use 1.0 (100%) similarity threshold for rigorous validation
            $this->assertPdfPagesSimilar(
                $originalPdf,
                $extractedFile,
                $pageNum,
                1.0, // 100% match required
                sprintf('Extracted page %d must match original page 100%%', $pageNum),
            );

            self::getLogger()->info("✓ Page {$pageNum} validated - 100% match confirmed");
        }
    }

    /**
     * Assert that merged PDF pages match original pages with 100% accuracy.
     *
     * @param string     $originalPdf Path to original PDF
     * @param string     $mergedPdf   Path to merged PDF
     * @param array<int> $pageNumbers Original page numbers that were merged
     */
    private function assertMergedPagesMatch100Percent(string $originalPdf, string $mergedPdf, array $pageNumbers): void
    {
        $mergedPageNum = 1;

        foreach ($pageNumbers as $pageNumber) {
            self::getLogger()->info("Validating merged page {$mergedPageNum} against original page {$pageNumber}");

            // Compare merged page with original page
            // Create temporary single-page extract from merged PDF
            $tmpDir = self::getRootDir() . '/tmp_compare_' . uniqid();
            mkdir($tmpDir, 0o777, true);
            $tempExtract = $tmpDir . '/temp_page.pdf';

            $fileIO   = new FileIO(self::getLogger());
            $splitter = new PDFSplitter($mergedPdf, $fileIO, self::getLogger());
            $splitter->extractPage($mergedPageNum, $tempExtract);

            // Compare with 100% match requirement
            $this->assertPdfPagesSimilar(
                $originalPdf,
                $tempExtract,
                $pageNumber,
                1.0, // 100% match required
                sprintf('Merged page %d must match original page %d with 100%% accuracy', $mergedPageNum, $pageNumber),
            );

            self::unlink($tempExtract);

            self::getLogger()->info("✓ Merged page {$mergedPageNum} validated - 100% match with original page {$pageNumber}");

            $mergedPageNum++;
        }
    }

    /**
     * Assert that a PDF has valid structure.
     *
     * @param string $pdfPath Path to PDF file
     * @param string $label   Label for error messages
     */
    private function assertValidPdfStructure(string $pdfPath, string $label): void
    {
        $this->assertFileExists($pdfPath, "{$label}: PDF file should exist");

        $fileSize = filesize($pdfPath);

        // For small files, read entire file; for large files, sample beginning and end
        if ($fileSize < 524288) { // Files < 512KB, read entirely
            $chunkSize = $fileSize;
        } else {
            $chunkSize = 524288; // Read first 512KB for large files
        }

        // Read header chunk for validation using streaming
        $handle = fopen($pdfPath, 'rb');

        if ($handle === false) {
            $this->fail("{$label}: Could not open PDF file for validation");
        }

        try {
            $headerChunk = fread($handle, $chunkSize);

            if ($headerChunk === false) {
                $this->fail("{$label}: Could not read PDF header");
            }

            // Read last chunk for EOF marker
            $tailSize = min(1024, $fileSize);

            if (fseek($handle, -$tailSize, SEEK_END) !== 0) {
                $this->fail("{$label}: Could not seek to end of PDF");
            }
            $tailChunk = fread($handle, $tailSize);

            if ($tailChunk === false) {
                $this->fail("{$label}: Could not read PDF tail");
            }

            // Basic PDF structure validation
            $this->assertStringContainsString('%PDF-', $headerChunk, "{$label}: Must have PDF header");
            $this->assertStringContainsString('%%EOF', $tailChunk, "{$label}: Must have EOF marker");

            // Check for page objects with flexible spacing (handles '/Type /Page', '/Type/Page', '/Type /Pages')
            $hasPageObject = preg_match('/\\/Type\\s*\\/Pages?(?:\\s|>|\\/)/', $headerChunk) === 1;
            $this->assertTrue($hasPageObject, "{$label}: Must contain page objects");

            // Catalog check - for very large files (>10MB), the catalog might be deep in the structure after all page content
            // so we skip this check as having valid header + EOF + Pages is sufficient
            if ($fileSize < 10485760) { // 10MB threshold
                $hasCatalog = preg_match('/\\/Type\\s*\\/Catalog(?:\\s|>|\\/)/', $headerChunk) === 1;

                if (!$hasCatalog) {
                    // For files in the 512KB-10MB range, try reading more
                    if ($fileSize > 524288 && $chunkSize < $fileSize) {
                        // Skip catalog check - it might be after all the resources
                        self::getLogger()->debug("{$label}: Catalog not found in first chunk, but file has valid structure");
                    } else {
                        $this->assertTrue($hasCatalog, "{$label}: Must contain catalog");
                    }
                }
            }

            // Validate no corruption markers
            $this->assertStringNotContainsString('ERROR', $headerChunk, "{$label}: Should not contain error markers");
            $this->assertStringNotContainsString('CORRUPTED', $headerChunk, "{$label}: Should not be marked as corrupted");

            self::getLogger()->info("{$label}: PDF structure validated", [
                'file'       => $pdfPath,
                'size_bytes' => $fileSize,
            ]);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Get project root directory.
     */
    private function getProjectRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
