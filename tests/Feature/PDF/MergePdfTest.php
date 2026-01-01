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

use Test\TestCase;
use PXP\PDF\Fpdf\FPDF;

/**
 * @covers \PXP\PDF\Fpdf\FPDF
 */
final class MergePdfTest extends TestCase
{
    public function test_merge_multiple_pdfs_into_single_pdf(): void
    {
        // Skip if mergePdf method doesn't exist yet
        if (!method_exists(FPDF::class, 'mergePdf')) {
            $this->markTestSkipped('mergePdf method not yet implemented');
        }

        // Get PDF files from input directory using glob
        $inputDir = dirname(__DIR__, 2) . '/resources/input';

        // Skip if directory doesn't exist
        if (!is_dir($inputDir)) {
            $this->markTestSkipped('Input directory not found: ' . $inputDir);
        }

        $pdfFilePaths = glob($inputDir . '/*.pdf') ?: [];

        // Skip if no PDF files are available
        if (empty($pdfFilePaths)) {
            $this->markTestSkipped('No PDF files found in tests/resources/input directory');
        }

        // Sort files for consistent test execution
        sort($pdfFilePaths);

        // Verify all input PDFs exist
        foreach ($pdfFilePaths as $pdfPath) {
            $this->assertFileExists($pdfPath, sprintf('Input PDF file not found: %s', $pdfPath));
        }

        // Create output directory
        $tmpDir = self::getRootDir() . '/tmp_merge_' . uniqid();
        mkdir($tmpDir, 0777, true);

        // Output path for merged PDF
        $mergedPdfPath = $tmpDir . '/merged.pdf';

        // Merge all PDFs
        FPDF::mergePdf(
            $pdfFilePaths,
            $mergedPdfPath,
            self::getLogger(),
            self::getCache(),
            self::getEventDispatcher(),
        );

        // Verify merged PDF exists
        $this->assertFileExists($mergedPdfPath, 'Merged PDF file was not created');

        // Verify merged PDF is not empty
        $this->assertGreaterThan(0, filesize($mergedPdfPath), 'Merged PDF file should not be empty');

        // Verify it's a valid PDF
        $content = file_get_contents($mergedPdfPath);
        $this->assertStringContainsString('%PDF-', $content, 'Merged file should be a valid PDF');

        // Calculate expected total page count
        $expectedTotalPages = 0;
        foreach ($pdfFilePaths as $pdfPath) {
            // Count pages in each input PDF by checking /Count in the PDF structure
            $pdfContent = file_get_contents($pdfPath);
            if (preg_match('/\/Count\s+(\d+)/', $pdfContent, $matches)) {
                $expectedTotalPages += (int) $matches[1];
            } else {
                // Fallback: assume at least 1 page if count not found
                $expectedTotalPages += 1;
            }
        }

        // Verify merged PDF has the expected number of pages
        if ($expectedTotalPages > 0) {
            $mergedContent = file_get_contents($mergedPdfPath);
            if (preg_match('/\/Count\s+(\d+)/', $mergedContent, $matches)) {
                $actualPageCount = (int) $matches[1];
                $this->assertEquals(
                    $expectedTotalPages,
                    $actualPageCount,
                    sprintf(
                        'Merged PDF should have %d pages (sum of all input PDFs), but has %d pages',
                        $expectedTotalPages,
                        $actualPageCount
                    )
                );
            }
        }

        // Clean up
        self::unlink($mergedPdfPath);
    }

    public function test_merge_empty_array_throws_exception(): void
    {
        // Skip if mergePdf method doesn't exist yet
        if (!method_exists(FPDF::class, 'mergePdf')) {
            $this->markTestSkipped('mergePdf method not yet implemented');
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one PDF file is required for merging');

        $tmpDir = self::getRootDir() . '/tmp_merge_' . uniqid();
        mkdir($tmpDir, 0777, true);
        $outputPath = $tmpDir . '/merged.pdf';

        FPDF::mergePdf([], $outputPath);
    }

    public function test_merge_with_nonexistent_file_throws_exception(): void
    {
        // Skip if mergePdf method doesn't exist yet
        if (!method_exists(FPDF::class, 'mergePdf')) {
            $this->markTestSkipped('mergePdf method not yet implemented');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PDF file not found');

        $tmpDir = self::getRootDir() . '/tmp_merge_' . uniqid();
        mkdir($tmpDir, 0777, true);
        $outputPath = $tmpDir . '/merged.pdf';
        $nonexistentFile = $tmpDir . '/nonexistent.pdf';

        FPDF::mergePdf([$nonexistentFile], $outputPath);
    }
}
