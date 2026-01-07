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
namespace Test\Traits;

use function basename;
use function count;
use function error_log;
use function file_exists;
use function getenv;
use function realpath;
use function sprintf;
use function str_contains;
use function substr;
use function trim;
use RuntimeException;

trait PdfAssertionsTrait
{
    /**
     * Assert that two PDFs have visually similar pages.
     *
     * @param string $originalPdf   Path to original PDF
     * @param string $splitPdf      Path to split/extracted PDF
     * @param int    $pageNumber    Page number to compare (1-based)
     * @param float  $minSimilarity Minimum similarity threshold (0.0 to 1.0, default: 0.95)
     * @param string $message       Optional assertion message
     */
    public function assertPdfPagesSimilar(
        string $originalPdf,
        string $splitPdf,
        int $pageNumber,
        float $minSimilarity = 0.95,
        string $message = ''
    ): void {
        try {
            // Resolve paths and verify files exist
            $originalPdf = realpath($originalPdf) ?: $originalPdf;
            $splitPdf    = realpath($splitPdf) ?: $splitPdf;

            if (!file_exists($originalPdf)) {
                throw new RuntimeException('Original PDF file not found: ' . $originalPdf);
            }

            if (!file_exists($splitPdf)) {
                throw new RuntimeException('Split PDF file not found: ' . $splitPdf);
            }

            // Check if PDFs have text content (to detect font rendering issues)
            $originalHasText = $this->pdfHasTextContent($originalPdf, $pageNumber);
            $splitHasText    = $this->pdfHasTextContent($splitPdf, 1);

            $originalImage = self::pdfToImage($originalPdf, $pageNumber);
            $splitImage    = self::pdfToImage($splitPdf, 1); // Split PDFs are single-page, always page 1

            // Check if images are blank when they shouldn't be
            $originalIsBlank = self::isImageMostlyWhite($originalImage);
            $splitIsBlank    = self::isImageMostlyWhite($splitImage);

            // If PDF has text but image is blank, this indicates a rendering problem
            // Instead of failing immediately, we'll still do the comparison but note the issue
            $renderingIssue = false;
            $issueMessage   = '';

            if ($originalHasText && $originalIsBlank) {
                $renderingIssue = true;
                $originalText   = $this->extractPdfText($originalPdf, $pageNumber);
                $issueMessage .= 'Original PDF has text ("' . substr($originalText, 0, 50) . '") but image is blank (font rendering issue). ';
            }

            if ($splitHasText && $splitIsBlank) {
                $renderingIssue = true;
                $splitText      = $this->extractPdfText($splitPdf, 1);
                $issueMessage .= 'Split PDF has text ("' . substr($splitText, 0, 50) . '") but image is blank. ';
            }

            // If both PDFs have text but both images are blank, use text comparison as fallback
            // This handles cases where the PDF-to-image tool can't render custom fonts
            if ($originalHasText && $splitHasText && $originalIsBlank && $splitIsBlank) {
                // Use text extraction for comparison instead of image comparison
                $originalText = $this->extractPdfText($originalPdf, $pageNumber);
                $splitText    = $this->extractPdfText($splitPdf, 1);

                // Clean up temporary images
                if (!getenv('PERSISTENT_PDF_TEST_FILES')) {
                    self::unlink($originalImage);
                    self::unlink($splitImage);
                }

                // Compare text content
                $textMatches = trim($originalText) === trim($splitText);

                $msg = $message ?: sprintf(
                    'PDF pages text content does not match | Original: %s | Split: %s',
                    basename($originalPdf),
                    basename($splitPdf),
                );

                $msg .= ' | NOTE: ' . $issueMessage .
                    'Using text extraction comparison due to font rendering limitations in image conversion.';

                $this->assertTrue(
                    $textMatches,
                    $msg . ' | Original text: "' . substr($originalText, 0, 100) . '" | Split text: "' . substr($splitText, 0, 100) . '"',
                );

                return; // Skip image comparison
            }

            // If both are blank due to rendering issues, the comparison will still work
            // but we'll get low similarity, which is correct behavior

            $similarity = self::compareImages($originalImage, $splitImage);

            // Debug: Log similarity and image paths if similarity is suspiciously high for different content
            if (getenv('PERSISTENT_PDF_TEST_FILES')) {
                error_log(sprintf(
                    'PDF Comparison Debug: similarity=%.4f, original=%s, split=%s, originalImage=%s, splitImage=%s',
                    $similarity,
                    $originalPdf,
                    $splitPdf,
                    $originalImage,
                    $splitImage,
                ));
            }

            // Clean up temporary images (unless debugging)
            if (!getenv('PERSISTENT_PDF_TEST_FILES')) {
                self::unlink($originalImage);
                self::unlink($splitImage);
            }

            $msg = $message ?: sprintf(
                'PDF pages are not similar enough. Similarity: %.2f%%, Required: %.2f%% | Original: %s | Split: %s',
                $similarity * 100,
                $minSimilarity * 100,
                basename($originalPdf),
                basename($splitPdf),
            );

            // Add rendering issue information to the message if applicable
            if ($renderingIssue) {
                $msg .= ' | NOTE: ' . $issueMessage .
                    'This may be due to font rendering limitations. ' .
                    'If both PDFs have text but images are blank, similarity will be low.';
            }

            $this->assertGreaterThanOrEqual(
                $minSimilarity,
                $similarity,
                $msg,
            );
        } catch (RuntimeException $e) {
            // Only skip if it's a "tools not available" error, otherwise let the test fail
            if (str_contains($e->getMessage(), 'No PDF to image conversion tool found')) {
                $this->markTestSkipped('PDF to image conversion not available: ' . $e->getMessage());
            } else {
                // Re-throw other exceptions so the test fails with a clear error
                throw $e;
            }
        }
    }

    /**
     * Assert that a PDF page matches expected visual content.
     *
     * @param string $pdfPath           Path to PDF file
     * @param int    $pageNumber        Page number to check (1-based)
     * @param string $expectedImagePath Path to expected image file
     * @param float  $minSimilarity     Minimum similarity threshold (0.0 to 1.0, default: 0.95)
     * @param string $message           Optional assertion message
     */
    public function assertPdfPageMatchesImage(
        string $pdfPath,
        int $pageNumber,
        string $expectedImagePath,
        float $minSimilarity = 0.95,
        string $message = ''
    ): void {
        try {
            $pdfImage = self::pdfToImage($pdfPath, $pageNumber);

            $similarity = self::compareImages($expectedImagePath, $pdfImage);

            // Clean up temporary image
            self::unlink($pdfImage);

            $msg = $message ?: sprintf(
                'PDF page does not match expected image. Similarity: %.2f%%, Required: %.2f%%',
                $similarity * 100,
                $minSimilarity * 100,
            );

            $this->assertGreaterThanOrEqual(
                $minSimilarity,
                $similarity,
                $msg,
            );
        } catch (RuntimeException $e) {
            // Only skip if it's a "tools not available" error, otherwise let the test fail
            if (str_contains($e->getMessage(), 'No PDF to image conversion tool found')) {
                $this->markTestSkipped('PDF to image conversion not available: ' . $e->getMessage());
            } else {
                // Re-throw other exceptions so the test fails with a clear error
                throw $e;
            }
        }
    }

    /**
     * Assert that all pages in a split PDF match the original PDF pages.
     *
     * @param string        $originalPdf   Path to original multi-page PDF
     * @param array<string> $splitPdfs     Array of paths to split single-page PDFs
     * @param float         $minSimilarity Minimum similarity threshold (0.0 to 1.0, default: 0.95)
     * @param string        $message       Optional assertion message
     */
    public function assertSplitPdfPagesMatchOriginal(
        string $originalPdf,
        array $splitPdfs,
        float $minSimilarity = 0.95,
        string $message = ''
    ): void {
        try {
            // Resolve paths and verify files exist
            $originalPdf = realpath($originalPdf) ?: $originalPdf;

            if (!file_exists($originalPdf)) {
                throw new RuntimeException('Original PDF file not found: ' . $originalPdf);
            }

            // Resolve all split PDF paths
            $resolvedSplitPdfs = [];

            foreach ($splitPdfs as $splitPdf) {
                $resolved = realpath($splitPdf) ?: $splitPdf;

                if (!file_exists($resolved)) {
                    throw new RuntimeException('Split PDF file not found: ' . $splitPdf);
                }
                $resolvedSplitPdfs[] = $resolved;
            }

            $originalImages = [];
            $splitImages    = [];
            $counter        = count($resolvedSplitPdfs);

            // Convert all original pages to images
            for ($i = 0; $i < $counter; $i++) {
                $originalImages[] = self::pdfToImage($originalPdf, $i + 1);
            }

            // Convert all split PDFs to images
            foreach ($resolvedSplitPdfs as $resolvedSplitPdf) {
                $splitImages[] = self::pdfToImage($resolvedSplitPdf, 1);
            }
            $counter = count($resolvedSplitPdfs);

            // Compare each pair
            for ($i = 0; $i < $counter; $i++) {
                $originalPageNum = $i + 1;
                $originalHasText = $this->pdfHasTextContent($originalPdf, $originalPageNum);
                $splitHasText    = $this->pdfHasTextContent($resolvedSplitPdfs[$i], 1);
                $originalIsBlank = self::isImageMostlyWhite($originalImages[$i]);
                $splitIsBlank    = self::isImageMostlyWhite($splitImages[$i]);

                // If both PDFs have text but both images are blank, use text comparison
                if ($originalHasText && $splitHasText && $originalIsBlank && $splitIsBlank) {
                    $originalText = $this->extractPdfText($originalPdf, $originalPageNum);
                    $splitText    = $this->extractPdfText($resolvedSplitPdfs[$i], 1);

                    // Clean up
                    self::unlink($originalImages[$i]);
                    self::unlink($splitImages[$i]);

                    $textMatches = trim($originalText) === trim($splitText);

                    $msg = $message ?: sprintf(
                        'Split PDF page %d text content does not match original page %d',
                        $i + 1,
                        $i + 1,
                    );

                    $msg .= ' | NOTE: Using text extraction comparison due to font rendering limitations.';

                    $this->assertTrue(
                        $textMatches,
                        $msg . ' | Original text: "' . substr($originalText, 0, 100) . '" | Split text: "' . substr($splitText, 0, 100) . '"',
                    );

                    continue; // Skip image comparison for this page
                }

                $similarity = self::compareImages($originalImages[$i], $splitImages[$i]);

                // Clean up
                self::unlink($originalImages[$i]);
                self::unlink($splitImages[$i]);

                $msg = $message ?: sprintf(
                    'Split PDF page %d does not match original page %d. Similarity: %.2f%%, Required: %.2f%%',
                    $i + 1,
                    $i + 1,
                    $similarity * 100,
                    $minSimilarity * 100,
                );

                $this->assertGreaterThanOrEqual(
                    $minSimilarity,
                    $similarity,
                    $msg,
                );
            }
        } catch (RuntimeException $e) {
            // Only skip if it's a "tools not available" error, otherwise let the test fail
            if (str_contains($e->getMessage(), 'No PDF to image conversion tool found')) {
                $this->markTestSkipped('PDF to image conversion not available: ' . $e->getMessage());
            } else {
                // Re-throw other exceptions so the test fails with a clear error
                throw $e;
            }
        }
    }
}
