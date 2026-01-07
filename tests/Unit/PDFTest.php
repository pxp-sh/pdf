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
namespace Test\Unit;

use function dirname;
use function file_exists;
use function filesize;
use function glob;
use function is_dir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use PXP\PDF;
use PXP\PDF\Fpdf\Core\FPDF;
use Test\TestCase;

/**
 * @covers \PXP\PDF
 */
final class PDFTest extends TestCase
{
    public function test_pdf_can_be_instantiated(): void
    {
        $pdf = new PDF;

        $this->assertInstanceOf(FPDF::class, $pdf);
        $this->assertInstanceOf(PDF::class, $pdf);
    }

    public function test_pdf_instantiation_with_parameters(): void
    {
        $pdf = new PDF('L', 'mm', 'A4');

        $this->assertInstanceOf(PDF::class, $pdf);
        // Just check that it instantiates without error
    }

    public function test_extract_text_static_method(): void
    {
        // Use a sample PDF from resources
        $pdfPath = dirname(__DIR__, 2) . '/tests/resources/PDF/input/23-grande.pdf';

        $this->assertFileExists($pdfPath);

        $extractedText = PDF::extractText($pdfPath);

        $this->assertIsString($extractedText);
        $this->assertNotEmpty($extractedText);
        // The PDF contains text, so it should extract something meaningful
        $this->assertStringContainsString('assinaturas', $extractedText);
    }

    public function test_extract_text_streaming_static_method(): void
    {
        // Use a sample PDF from resources
        $pdfPath = dirname(__DIR__, 2) . '/tests/resources/PDF/input/23-grande.pdf';

        $this->assertFileExists($pdfPath);

        $collectedText = '';
        $pageCount     = 0;

        PDF::extractTextStreaming(static function (string $text, int $pageNumber) use (&$collectedText, &$pageCount): void
        {
            $collectedText .= $text . "\n";
            $pageCount = $pageNumber;
        }, $pdfPath);

        $this->assertGreaterThan(0, $pageCount);
        $this->assertNotEmpty($collectedText);
        // The PDF contains text, so it should extract something meaningful
        $this->assertStringContainsString('assinaturas', $collectedText);
    }

    public function test_split_pdf_static_method(): void
    {
        // Create a test PDF with multiple pages
        $fpdf = self::createFPDF();
        $fpdf->setFont('Arial', '', 12);
        $fpdf->addPage();
        $fpdf->cell(0, 10, 'Page 1 Content');
        $fpdf->addPage();
        $fpdf->cell(0, 10, 'Page 2 Content');
        $fpdf->addPage();
        $fpdf->cell(0, 10, 'Page 3 Content');

        $inputPdf = sys_get_temp_dir() . '/' . uniqid('pdf_split_input_', true) . '.pdf';
        $fpdf->output('F', $inputPdf);

        $outputDir = sys_get_temp_dir() . '/' . uniqid('pdf_split_output_', true);

        try {
            $filePaths = PDF::splitPdf($inputPdf, $outputDir);

            $this->assertIsArray($filePaths);
            $this->assertCount(3, $filePaths);

            // Check that files were created
            foreach ($filePaths as $filePath) {
                $this->assertFileExists($filePath);
                $this->assertStringEndsWith('.pdf', $filePath);
                $this->assertGreaterThan(0, filesize($filePath));
            }
        } finally {
            // Cleanup
            if (file_exists($inputPdf)) {
                unlink($inputPdf);
            }

            foreach (glob($outputDir . '/*') as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }

            if (is_dir($outputDir)) {
                rmdir($outputDir);
            }
        }
    }

    public function test_extract_page_static_method(): void
    {
        // Create a test PDF with multiple pages
        $fpdf = self::createFPDF();
        $fpdf->setFont('Arial', '', 12);
        $fpdf->addPage();
        $fpdf->cell(0, 10, 'Page 1 Content');
        $fpdf->addPage();
        $fpdf->cell(0, 10, 'Page 2 Content');

        $inputPdf = sys_get_temp_dir() . '/' . uniqid('pdf_extract_input_', true) . '.pdf';
        $fpdf->output('F', $inputPdf);

        $outputPath = sys_get_temp_dir() . '/' . uniqid('pdf_extract_output_', true) . '.pdf';

        try {
            PDF::extractPage($inputPdf, 2, $outputPath);

            $this->assertFileExists($outputPath);
            $this->assertGreaterThan(0, filesize($outputPath));
        } finally {
            // Cleanup
            if (file_exists($inputPdf)) {
                unlink($inputPdf);
            }

            if (file_exists($outputPath)) {
                unlink($outputPath);
            }
        }
    }

    public function test_merge_pdf_static_method(): void
    {
        // Create two test PDFs
        $fpdf1 = self::createFPDF();
        $fpdf1->setFont('Arial', '', 12);
        $fpdf1->addPage();
        $fpdf1->cell(0, 10, 'PDF 1 Content');

        $inputPdf1 = sys_get_temp_dir() . '/' . uniqid('pdf_merge_input1_', true) . '.pdf';
        $fpdf1->output('F', $inputPdf1);

        $fpdf2 = self::createFPDF();
        $fpdf2->setFont('Arial', '', 12);
        $fpdf2->addPage();
        $fpdf2->cell(0, 10, 'PDF 2 Content');

        $inputPdf2 = sys_get_temp_dir() . '/' . uniqid('pdf_merge_input2_', true) . '.pdf';
        $fpdf2->output('F', $inputPdf2);

        $outputPath = sys_get_temp_dir() . '/' . uniqid('pdf_merge_output_', true) . '.pdf';

        try {
            PDF::mergePdf([$inputPdf1, $inputPdf2], $outputPath);

            $this->assertFileExists($outputPath);
            $this->assertGreaterThan(0, filesize($outputPath));
        } finally {
            // Cleanup
            if (file_exists($inputPdf1)) {
                unlink($inputPdf1);
            }

            if (file_exists($inputPdf2)) {
                unlink($inputPdf2);
            }

            if (file_exists($outputPath)) {
                unlink($outputPath);
            }
        }
    }
}
