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
use PXP\PDF\Fpdf\Splitter\PDFMerger;
use PXP\PDF\Fpdf\IO\FileIO;

/**
 * @covers \PXP\PDF\Fpdf\Splitter\PDFMerger
 */
final class MergePdfTest extends TestCase
{
    public function test_merge_multiple_pdfs_into_single_pdf(): void
    {
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

        // Create merger and use incremental merge
        $fileIO = new FileIO(self::getLogger());
        $merger = new PDFMerger($fileIO, self::getLogger(), self::getEventDispatcher(), self::getCache());
        $merger->mergeIncremental($pdfFilePaths, $mergedPdfPath);

        // Verify merged PDF exists
        $this->assertFileExists($mergedPdfPath, 'Merged PDF file was not created');

        // Verify merged PDF is not empty
        $this->assertGreaterThan(0, filesize($mergedPdfPath), 'Merged PDF file should not be empty');

        // Verify it's a valid PDF (read only the header to avoid loading large file into memory)
        $header = file_get_contents($mergedPdfPath, false, null, 0, 8);
        $this->assertIsString($header, 'Could not read PDF header');
        $this->assertStringContainsString('%PDF-', $header, 'Merged file should be a valid PDF');

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

                    // If similarity is low, try boundary pages (previous and next) to detect page shifts
                    $matchedPage = null;
                    $bestSimilarity = $similarity;
                    $bestMatchPage = $candidate;

                    if ($similarity < 0.90) {
                        // Try previous page (if exists)
                        if ($candidate > 1) {
                            $imgMergedPrev = self::pdfToImage($mergedPdfPath, $candidate - 1);
                            $similarityPrev = self::compareImages($imgOrig, $imgMergedPrev);
                            self::unlink($imgMergedPrev);

                            if ($similarityPrev > $bestSimilarity) {
                                $bestSimilarity = $similarityPrev;
                                $bestMatchPage = $candidate - 1;
                            }
                        }

                        // Try next page (if exists)
                        if ($candidate < $mergedPageCount) {
                            $imgMergedNext = self::pdfToImage($mergedPdfPath, $candidate + 1);
                            $similarityNext = self::compareImages($imgOrig, $imgMergedNext);
                            self::unlink($imgMergedNext);

                            if ($similarityNext > $bestSimilarity) {
                                $bestSimilarity = $similarityNext;
                                $bestMatchPage = $candidate + 1;
                            }
                        }

                        if ($bestSimilarity >= 0.90) {
                            $matchedPage = $bestMatchPage;
                            $this->addWarning(sprintf(
                                'Page offset detected: source %s page %d matched merged page %d instead of expected page %d (similarity %.3f)',
                                basename($srcPdf),
                                $p,
                                $bestMatchPage,
                                $candidate,
                                $bestSimilarity
                            ));
                        }
                    } else {
                        $matchedPage = $candidate;
                    }

                    // cleanup images
                    self::unlink($imgOrig);
                    self::unlink($imgMerged);

                    if ($matchedPage === null) {
                        $this->fail(sprintf(
                            'Visual mismatch for source %s page %d. Expected at merged page %d (similarity %.3f), checked boundaries: prev=%d, next=%d (best match: page %d with %.3f)',
                            basename($srcPdf),
                            $p,
                            $candidate,
                            $similarity,
                            max(1, $candidate - 1),
                            min($mergedPageCount, $candidate + 1),
                            $bestMatchPage,
                            $bestSimilarity
                        ));
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
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one PDF file is required for merging');

        $tmpDir = self::getRootDir() . '/tmp_merge_' . uniqid();
        mkdir($tmpDir, 0777, true);
        $outputPath = $tmpDir . '/merged.pdf';

        $fileIO = new FileIO(self::getLogger());
        $merger = new PDFMerger($fileIO, self::getLogger());
        $merger->mergeIncremental([], $outputPath);
    }

    public function test_merge_with_nonexistent_file_throws_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PDF file not found');

        $tmpDir = self::getRootDir() . '/tmp_merge_' . uniqid();
        mkdir($tmpDir, 0777, true);
        $outputPath = $tmpDir . '/merged.pdf';
        $nonexistentFile = $tmpDir . '/nonexistent.pdf';

        $fileIO = new FileIO(self::getLogger());
        $merger = new PDFMerger($fileIO, self::getLogger());
        $merger->mergeIncremental([$nonexistentFile], $outputPath);
    }
}
