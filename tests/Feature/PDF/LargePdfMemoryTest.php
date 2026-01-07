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
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function gc_collect_cycles;
use function getenv;
use function memory_get_peak_usage;
use function mkdir;
use function sprintf;
use function str_repeat;
use function uniqid;
use PXP\PDF\Fpdf\Core\FPDF;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\FPDF
 *
 * @group large
 */
final class LargePdfMemoryTest extends TestCase
{
    public function test_large_pdf_memory_usage_for_file_output(): void
    {
        // This test is expensive and disabled by default. Enable by setting RUN_LARGE_TESTS=1 in the environment.
        if (!getenv('RUN_LARGE_TESTS')) {
            $this->markTestSkipped('Large tests disabled; set RUN_LARGE_TESTS=1 to enable.');
        }

        $pages          = 1200; // +1000 pages
        $thresholdBytes = 50 * 1024 * 1024; // 50 MB allowed growth for writing to file

        // Create small stub font to avoid bundling real font files
        $tmpDir = self::getRootDir() . '/tmp_pf_' . uniqid();
        mkdir($tmpDir, 0o777, true);
        $fontFile = $tmpDir . '/testfont.php';
        file_put_contents($fontFile, '<?php $name="TestFont"; $type="Core"; $cw=[];');

        $fpdf = self::createFPDF();
        $fpdf->setCompression(false);
        $fpdf->addFont('TestFont', '', 'testfont.php', $tmpDir);
        $fpdf->setFont('TestFont', '', 12);

        // Ensure baseline memory snapshot
        gc_collect_cycles();
        $startPeak = memory_get_peak_usage(true);

        // Generate pages with repeated text to simulate a text-heavy document
        $sampleLine = str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 12);

        for ($p = 0; $p < $pages; $p++) {
            $fpdf->addPage();

            // Add enough lines to fill the page
            for ($i = 0; $i < 30; $i++) {
                $fpdf->multiCell(0, 6, $sampleLine);
            }
        }

        $tmpFile = self::getRootDir() . '/large_pdf_' . uniqid() . '.pdf';

        // Write out to file (streaming-friendly code should keep memory growth bounded)
        $fpdf->output('F', $tmpFile);
        // split first page
        FPDF::extractPage($tmpFile, 1, self::getRootDir() . '/large_pdf_split_1.pdf');

        $afterPeak = memory_get_peak_usage(true);
        $peakDelta = $afterPeak - $startPeak;

        // Validate the file was created and is non-empty
        $this->assertFileExists($tmpFile);
        $this->assertGreaterThan(0, filesize($tmpFile), 'Generated PDF file should not be empty.');

        // Verify visual correctness of a sample page (first page)
        // Create a reference PDF with the same content for comparison
        $referencePdf = self::createFPDF();
        $referencePdf->setCompression(false);
        $referencePdf->addFont('TestFont', '', 'testfont.php', $tmpDir);
        $referencePdf->setFont('TestFont', '', 12);
        $referencePdf->addPage();

        for ($i = 0; $i < 30; $i++) {
            $referencePdf->multiCell(0, 6, $sampleLine);
        }
        $referenceFile = self::getRootDir() . '/large_pdf_reference_' . uniqid() . '.pdf';
        $referencePdf->output('F', $referenceFile);

        // Verify first page of large PDF matches reference
        $this->assertPdfPagesSimilar($referenceFile, $tmpFile, 1, 0.90, 'First page of large PDF should match reference');

        // Clean up artifacts
        self::unlink($referenceFile);

        if (file_exists($tmpFile)) {
            self::unlink($tmpFile);
        }
        self::unlink($fontFile);

        // Assert memory growth stayed within acceptable bounds
        $this->assertLessThan(
            $thresholdBytes,
            $peakDelta,
            sprintf('Memory usage increased by %d bytes when writing %d pages to file; expected < %d bytes', $peakDelta, $pages, $thresholdBytes),
        );
    }

    public function test_large_pdf_split_and_validate_pages(): void
    {
        // This test is expensive and disabled by default. Enable by setting RUN_LARGE_TESTS=1 in the environment.
        if (!getenv('RUN_LARGE_TESTS')) {
            $this->markTestSkipped('Large tests disabled; set RUN_LARGE_TESTS=1 to enable.');
        }

        // Use a reasonable number of pages for validation (not too many to keep test time reasonable)
        $pages = 5000;

        // Create small stub font to avoid bundling real font files
        $tmpDir = self::getRootDir() . '/tmp_pf_' . uniqid();
        mkdir($tmpDir, 0o777, true);
        $fontFile = $tmpDir . '/testfont.php';
        file_put_contents($fontFile, '<?php $name="TestFont"; $type="Core"; $cw=[];');

        $fpdf = self::createFPDF();
        $fpdf->setCompression(false);
        $fpdf->addFont('TestFont', '', 'testfont.php', $tmpDir);
        $fpdf->setFont('TestFont', '', 12);

        // Generate pages with unique content on each page for validation
        $sampleLine = str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 12);

        for ($p = 1; $p <= $pages; $p++) {
            $fpdf->addPage();
            // Add page number to make each page unique
            $fpdf->cell(0, 10, sprintf('Page %d of %d', $p, $pages));
            $fpdf->ln(10);

            // Add enough lines to fill the page
            for ($i = 0; $i < 30; $i++) {
                $fpdf->multiCell(0, 6, $sampleLine);
            }
        }

        $originalPdf = $tmpDir . '/large_original.pdf';
        $fpdf->output('F', $originalPdf);

        // Validate the original PDF was created
        $this->assertFileExists($originalPdf);
        $this->assertGreaterThan(0, filesize($originalPdf), 'Generated PDF file should not be empty.');

        // Split the large PDF into individual pages
        $outputDir  = $tmpDir . '/split';
        $splitFiles = FPDF::splitPdf($originalPdf, $outputDir, 'page_%d.pdf');

        // Verify we got the correct number of split files
        $this->assertCount($pages, $splitFiles, sprintf('Expected %d split files, got %d', $pages, count($splitFiles)));

        // Verify each split file exists and is valid
        foreach ($splitFiles as $index => $file) {
            $this->assertFileExists($file, sprintf('Split file %d should exist', $index + 1));
            $this->assertGreaterThan(0, filesize($file), sprintf('Split file %d should not be empty', $index + 1));

            // Verify it's a valid PDF with exactly 1 page
            $content = file_get_contents($file);
            $this->assertStringContainsString('%PDF-', $content, sprintf('Split file %d should be a valid PDF', $index + 1));
            $this->assertStringContainsString('/Count 1', $content, sprintf('Split file %d should have exactly 1 page', $index + 1));
        }

        // Validate each split page matches the corresponding original page
        // This will compare all pages page-by-page
        $this->assertSplitPdfPagesMatchOriginal(
            $originalPdf,
            $splitFiles,
            0.90,
            sprintf('All %d split pages should match their corresponding original pages', $pages),
        );

        // Clean up
        foreach ($splitFiles as $splitFile) {
            self::unlink($splitFile);
        }

        if (file_exists($originalPdf)) {
            self::unlink($originalPdf);
        }
        self::unlink($fontFile);
    }
}
