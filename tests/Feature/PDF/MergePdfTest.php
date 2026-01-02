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

        // Calculate expected total page count using robust helper
        $expectedTotalPages = 0;
        foreach ($pdfFilePaths as $pdfPath) {
            $expectedTotalPages += self::getPdfPageCount($pdfPath);
        }

        // Verify merged PDF has the expected number of pages
        if ($expectedTotalPages > 0) {
            $actualPageCount = self::getPdfPageCount($mergedPdfPath);
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

        // Validate visual correctness of each merged page against the original source pages
        // For each source page attempt to find a matching merged page starting from the expected position.
        // This helps detect cases where trailing pages are blank or shifted.
        $globalPage = 1;
        $pagesCompared = 0;
        $pagesSkipped = 0;
        $skippedReasons = [];

        // Number of pages in the merged PDF
        $mergedPageCount = self::getPdfPageCount($mergedPdfPath);
        $maxLookahead = 5; // how many merged pages ahead we'll search for a match

        try {
            foreach ($pdfFilePaths as $srcPdf) {
                $srcPageCount = self::getPdfPageCount($srcPdf);

                for ($p = 1; $p <= $srcPageCount; ++$p) {
                    // Render source page image
                    try {
                        $imgOrig = self::pdfToImage($srcPdf, $p);
                    } catch (\RuntimeException $e) {
                        if (str_contains($e->getMessage(), 'No PDF to image conversion tool found')) {
                            $this->markTestSkipped('PDF to image conversion not available: ' . $e->getMessage());
                        }
                        throw $e;
                    }

                    $origBlank = self::isImageMostlyWhite($imgOrig);

                    // Strict per-source-page mapping: the source's next page must map to the merged page at position $globalPage.
                    $candidate = $globalPage;
                    if ($candidate > $mergedPageCount) {
                        self::unlink($imgOrig);
                        $this->fail(sprintf('Merged PDF missing expected page %d for source %s page %d', $candidate, basename($srcPdf), $p));
                    }

                    try {
                        $imgMerged = self::pdfToImage($mergedPdfPath, $candidate);
                    } catch (\RuntimeException $e) {
                        if (str_contains($e->getMessage(), 'No PDF to image conversion tool found')) {
                            self::unlink($imgOrig);
                            $this->markTestSkipped('PDF to image conversion not available: ' . $e->getMessage());
                        }
                        throw $e;
                    }

                    $mergedBlank = self::isImageMostlyWhite($imgMerged);

                    if ($origBlank && $mergedBlank) {
                        // Both render blank — skip this source page
                        self::unlink($imgOrig);
                        self::unlink($imgMerged);
                        ++$pagesSkipped;
                        $skippedReasons[] = sprintf('Page %d from %s appears blank when rendered', $p, basename($srcPdf));
                        // advance to next merged page
                        $globalPage++;
                        continue;
                    }

                    if (!$origBlank && $mergedBlank) {
                        // Non-blank source mapped to blank merged page — this is an error
                        self::unlink($imgOrig);
                        self::unlink($imgMerged);
                        $this->fail(sprintf('Merged PDF has blank page %d while source %s page %d is non-blank', $candidate, basename($srcPdf), $p));
                    }

                    // Both non-blank: compare visually
                    $similarity = self::compareImages($imgOrig, $imgMerged);

                    // cleanup images
                    self::unlink($imgOrig);
                    self::unlink($imgMerged);

                    if ($similarity < 0.90) {
                        $this->fail(sprintf('Visual mismatch for source %s page %d vs merged page %d (similarity %.3f)', basename($srcPdf), $p, $candidate, $similarity));
                    }

                    // success — advance expected merged page
                    $pagesCompared++;
                    $globalPage++;
                    // If we've exhausted merged pages, stop early
                    if ($globalPage > $mergedPageCount) {
                        break 2; // break out of both loops; remaining source pages cannot be matched
                    }
                }
            }
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'No PDF to image conversion tool found')) {
                $this->markTestSkipped('PDF to image conversion not available: ' . $e->getMessage());
            }
            throw $e;
        }

        if ($pagesCompared === 0 && $pagesSkipped > 0) {
            $this->markTestSkipped('All pages were skipped because they render as blank in this environment: ' . implode('; ', $skippedReasons));
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
