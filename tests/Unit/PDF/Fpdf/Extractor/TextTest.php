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
        $fpdf = self::createFPDF();
        $fpdf->setCompression(false); // Disable compression for easier testing
        $fpdf->addPage();
        $fpdf->setFont('Arial', '', 12);
        $fpdf->cell(0, 10, 'Hello World!');
        $fpdf->ln();
        $fpdf->cell(0, 10, 'This is a test PDF.');

        // Generate PDF content
        $pdfContent = $fpdf->output('S', 'test.pdf');

        // Extract text from the PDF
        $text          = new Text;
        $extractedText = $text->extractFromString($pdfContent);

        // Verify the extracted text contains the expected text
        $this->assertStringContainsString('Hello World!', $extractedText);
        $this->assertStringContainsString('This is a test PDF.', $extractedText);
    }

    public function test_extract_text_from_pdf_with_multiple_pages(): void
    {
        // Create a PDF with multiple pages
        $fpdf = self::createFPDF();
        $fpdf->setCompression(false);

        // Page 1
        $fpdf->addPage();
        $fpdf->setFont('Arial', '', 12);
        $fpdf->cell(0, 10, 'Page 1 Content');

        // Page 2
        $fpdf->addPage();
        $fpdf->cell(0, 10, 'Page 2 Content');

        // Page 3
        $fpdf->addPage();
        $fpdf->cell(0, 10, 'Page 3 Content');

        $pdfContent = $fpdf->output('S', 'test.pdf');

        $text          = new Text;
        $extractedText = $text->extractFromString($pdfContent);

        $this->assertStringContainsString('Page 1 Content', $extractedText);
        $this->assertStringContainsString('Page 2 Content', $extractedText);
        $this->assertStringContainsString('Page 3 Content', $extractedText);
    }

    public function test_extract_text_from_pdf_file(): void
    {
        // Create a PDF and save it to a file
        $fpdf = self::createFPDF();
        $fpdf->setCompression(false);
        $fpdf->addPage();
        $fpdf->setFont('Arial', '', 12);
        $fpdf->cell(0, 10, 'File content test');

        $tempFile = sys_get_temp_dir() . '/test_extract_' . uniqid() . '.pdf';
        $fpdf->output('F', $tempFile);

        // Extract text from the file
        $text          = new Text;
        $extractedText = $text->extractFromFile($tempFile);

        $this->assertStringContainsString('File content test', $extractedText);

        // Cleanup
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    public function test_extract_empty_text_from_empty_pdf(): void
    {
        // Create a PDF without any text
        $fpdf = self::createFPDF();
        $fpdf->setCompression(false);
        $fpdf->addPage();

        $pdfContent = $fpdf->output('S', 'test.pdf');

        $text          = new Text;
        $extractedText = $text->extractFromString($pdfContent);

        // Should return empty string or whitespace only
        $this->assertEmpty(trim($extractedText));
    }

    public function test_extract_text_with_special_characters(): void
    {
        // Create a PDF with special characters
        $fpdf = self::createFPDF();
        $fpdf->setCompression(false);
        $fpdf->addPage();
        $fpdf->setFont('Arial', '', 12);
        $fpdf->cell(0, 10, 'Special: !@#$%^&*()');

        $pdfContent = $fpdf->output('S', 'test.pdf');

        $text          = new Text;
        $extractedText = $text->extractFromString($pdfContent);

        $this->assertStringContainsString('Special:', $extractedText);
    }
}
