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
 * @covers \PXP\PDF\Fpdf\Splitter\PDFSplitter
 */
final class SplitPdfTest extends TestCase
{
    public function test_split_pdf_into_individual_pages(): void
    {
        // Create a multi-page PDF
        $pdf = self::createFPDF();
        $pdf->setCompression(false);

        $tmpDir = self::getRootDir() . '/tmp_pf_' . uniqid();
        mkdir($tmpDir, 0777, true);
        // Use built-in Helvetica font (standard PDF core font for image rendering)
        $pdf->setFont('Helvetica', '', 12);

        // Add 3 pages with different content
        $pdf->addPage();
        $pdf->cell(0, 10, 'Page 1 Content');

        $pdf->addPage();
        $pdf->cell(0, 10, 'Page 2 Content');

        $pdf->addPage();
        $pdf->cell(0, 10, 'Page 3 Content');

        // Save the original PDF
        $originalPdf = $tmpDir . '/original.pdf';
        $pdf->output('F', $originalPdf);

        $this->assertFileExists($originalPdf);

        // Split the PDF
        $outputDir = $tmpDir . '/split';
        $splitFiles = FPDF::splitPdf($originalPdf, $outputDir, 'page_%d.pdf');

        // Verify we got 3 files
        $this->assertCount(3, $splitFiles);

        // Verify each file exists
        foreach ($splitFiles as $file) {
            $this->assertFileExists($file);
            $this->assertGreaterThan(0, filesize($file), 'Split PDF file should not be empty');

            // Verify it's a valid PDF
            $content = file_get_contents($file);
            $this->assertStringContainsString('%PDF-', $content);
            $this->assertStringContainsString('/Count 1', $content, 'Each split PDF should have exactly 1 page');
        }

        // Verify visual similarity of split pages with original
        $this->assertSplitPdfPagesMatchOriginal($originalPdf, $splitFiles, 0.90);

        // Clean up
        foreach ($splitFiles as $file) {
            self::unlink($file);
        }
        self::unlink($originalPdf);
    }

    public function test_extract_single_page_from_pdf(): void
    {
        // Create a multi-page PDF
        $pdf = self::createFPDF();
        $pdf->setCompression(false);

        $tmpDir = self::getRootDir() . '/tmp_pf_' . uniqid();
        mkdir($tmpDir, 0777, true);
        // Use built-in Helvetica font (standard PDF core font for image rendering)
        $pdf->setFont('Helvetica', '', 12);

        // Add 3 pages
        $pdf->addPage();
        $pdf->cell(0, 10, 'Page 1');

        $pdf->addPage();
        $pdf->cell(0, 10, 'Page 2');

        $pdf->addPage();
        $pdf->cell(0, 10, 'Page 3');

        // Save the original PDF
        $originalPdf = $tmpDir . '/original.pdf';
        $pdf->output('F', $originalPdf);

        // Extract page 2
        $extractedPage = $tmpDir . '/page2.pdf';
        FPDF::extractPage($originalPdf, 2, $extractedPage);

        // Verify the extracted page exists
        $this->assertFileExists($extractedPage);
        $this->assertGreaterThan(0, filesize($extractedPage));

        // Verify it's a valid PDF with 1 page
        $content = file_get_contents($extractedPage);
        $this->assertStringContainsString('%PDF-', $content);
        $this->assertStringContainsString('/Count 1', $content);

        // Verify visual similarity with original page 2
        $this->assertPdfPagesSimilar($originalPdf, $extractedPage, 2, 0.90);

        // Clean up
        self::unlink($extractedPage);
        self::unlink($originalPdf);
    }

    public function test_split_pdf_with_custom_filename_pattern(): void
    {
        // Create a multi-page PDF
        $pdf = self::createFPDF();
        $pdf->setCompression(false);

        $tmpDir = self::getRootDir() . '/tmp_pf_' . uniqid();
        mkdir($tmpDir, 0777, true);
        // Use built-in Helvetica font (standard PDF core font for image rendering)
        $pdf->setFont('Helvetica', '', 12);

        $pdf->addPage();
        $pdf->cell(0, 10, 'Page 1');
        $pdf->addPage();
        $pdf->cell(0, 10, 'Page 2');

        $originalPdf = $tmpDir . '/original.pdf';
        $pdf->output('F', $originalPdf);

        // Split with custom pattern
        $outputDir = $tmpDir . '/split';
        $splitFiles = FPDF::splitPdf($originalPdf, $outputDir, 'document_page_%d.pdf');

        $this->assertCount(2, $splitFiles);
        $this->assertStringContainsString('document_page_1.pdf', $splitFiles[0]);
        $this->assertStringContainsString('document_page_2.pdf', $splitFiles[1]);

        // Clean up
        foreach ($splitFiles as $file) {
            self::unlink($file);
        }
        self::unlink($originalPdf);
    }
}
