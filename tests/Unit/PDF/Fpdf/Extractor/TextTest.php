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
namespace Test\Unit\PDF\Fpdf\Extractor;

use function file_exists;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;
use PXP\PDF\Fpdf\Features\Extractor\Text;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Extractor\Text
 */
final class TextTest extends TestCase
{
    public function test_extract_text_from_simple_pdf(): void
    {
        // Create a simple PDF with some text
        $pdf = self::createFPDF();
        $pdf->setCompression(false); // Disable compression for easier testing
        $pdf->addPage();
        $pdf->setFont('Arial', '', 12);
        $pdf->cell(0, 10, 'Hello World!');
        $pdf->ln();
        $pdf->cell(0, 10, 'This is a test PDF.');

        // Generate PDF content
        $pdfContent = $pdf->output('S', 'test.pdf');

        // Extract text from the PDF
        $extractor     = new Text;
        $extractedText = $extractor->extractFromString($pdfContent);

        // Verify the extracted text contains the expected text
        $this->assertStringContainsString('Hello World!', $extractedText);
        $this->assertStringContainsString('This is a test PDF.', $extractedText);
    }

    public function test_extract_text_from_pdf_with_multiple_pages(): void
    {
        // Create a PDF with multiple pages
        $pdf = self::createFPDF();
        $pdf->setCompression(false);

        // Page 1
        $pdf->addPage();
        $pdf->setFont('Arial', '', 12);
        $pdf->cell(0, 10, 'Page 1 Content');

        // Page 2
        $pdf->addPage();
        $pdf->cell(0, 10, 'Page 2 Content');

        // Page 3
        $pdf->addPage();
        $pdf->cell(0, 10, 'Page 3 Content');

        $pdfContent = $pdf->output('S', 'test.pdf');

        $extractor     = new Text;
        $extractedText = $extractor->extractFromString($pdfContent);

        $this->assertStringContainsString('Page 1 Content', $extractedText);
        $this->assertStringContainsString('Page 2 Content', $extractedText);
        $this->assertStringContainsString('Page 3 Content', $extractedText);
    }

    public function test_extract_text_from_pdf_file(): void
    {
        // Create a PDF and save it to a file
        $pdf = self::createFPDF();
        $pdf->setCompression(false);
        $pdf->addPage();
        $pdf->setFont('Arial', '', 12);
        $pdf->cell(0, 10, 'File content test');

        $tempFile = sys_get_temp_dir() . '/test_extract_' . uniqid() . '.pdf';
        $pdf->output('F', $tempFile);

        // Extract text from the file
        $extractor     = new Text;
        $extractedText = $extractor->extractFromFile($tempFile);

        $this->assertStringContainsString('File content test', $extractedText);

        // Cleanup
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    public function test_extract_empty_text_from_empty_pdf(): void
    {
        // Create a PDF without any text
        $pdf = self::createFPDF();
        $pdf->setCompression(false);
        $pdf->addPage();

        $pdfContent = $pdf->output('S', 'test.pdf');

        $extractor     = new Text;
        $extractedText = $extractor->extractFromString($pdfContent);

        // Should return empty string or whitespace only
        $this->assertEmpty(trim($extractedText));
    }

    public function test_extract_text_with_special_characters(): void
    {
        // Create a PDF with special characters
        $pdf = self::createFPDF();
        $pdf->setCompression(false);
        $pdf->addPage();
        $pdf->setFont('Arial', '', 12);
        $pdf->cell(0, 10, 'Special: !@#$%^&*()');

        $pdfContent = $pdf->output('S', 'test.pdf');

        $extractor     = new Text;
        $extractedText = $extractor->extractFromString($pdfContent);

        $this->assertStringContainsString('Special:', $extractedText);
    }
}
