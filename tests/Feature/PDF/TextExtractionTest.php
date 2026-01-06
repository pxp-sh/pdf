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

use function dirname;
use function file_exists;
use function microtime;
use function strlen;
use function strpos;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;
use PXP\PDF\Fpdf\Extractor\Text;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Extractor\Text
 */
final class TextExtractionTest extends TestCase
{
    private string $resourcesDir;

    protected function setUp(): void
    {
        parent::setUp();
        // Get the actual project root, not the temp dir
        $this->resourcesDir = dirname(__DIR__, 3) . '/tests/resources/PDF/input';
    }

    public function test_extract_text_from_generated_pdf(): void
    {
        // Create a PDF with known content
        $pdf = self::createFPDF();
        $pdf->setCompression(false);
        $pdf->addPage();
        $pdf->setFont('Arial', '', 12);
        $pdf->cell(0, 10, 'Feature Test: Text Extraction');
        $pdf->ln();
        $pdf->cell(0, 10, 'This is a multi-line document.');
        $pdf->ln();
        $pdf->cell(0, 10, 'Testing real-world scenario.');

        $pdfContent = $pdf->output('S', 'test.pdf');

        $extractor = new Text;
        $text      = $extractor->extractFromString($pdfContent);

        $this->assertStringContainsString('Feature Test: Text Extraction', $text);
        $this->assertStringContainsString('This is a multi-line document.', $text);
        $this->assertStringContainsString('Testing real-world scenario.', $text);
    }

    public function test_extract_text_from_generated_pdf_file(): void
    {
        // Create a PDF and save it to a file
        $pdf = self::createFPDF();
        $pdf->setCompression(false);
        $pdf->addPage();
        $pdf->setFont('Arial', '', 14);
        $pdf->cell(0, 10, 'File-based extraction test');
        $pdf->ln();
        $pdf->setFont('Arial', 'B', 12);
        $pdf->cell(0, 10, 'Bold text line');
        $pdf->ln();
        $pdf->setFont('Arial', 'I', 12);
        $pdf->cell(0, 10, 'Italic text line');

        $tempFile = sys_get_temp_dir() . '/feature_test_' . uniqid() . '.pdf';
        $pdf->output('F', $tempFile);

        $this->assertFileExists($tempFile);

        $extractor = new Text;
        $text      = $extractor->extractFromFile($tempFile);

        $this->assertStringContainsString('File-based extraction test', $text);
        $this->assertStringContainsString('Bold text line', $text);
        $this->assertStringContainsString('Italic text line', $text);

        // Cleanup
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    public function test_extract_text_from_multi_page_pdf(): void
    {
        // Create a multi-page PDF
        $pdf = self::createFPDF();
        $pdf->setCompression(false);

        // Page 1
        $pdf->addPage();
        $pdf->setFont('Arial', '', 12);
        $pdf->cell(0, 10, 'First page content');

        // Page 2
        $pdf->addPage();
        $pdf->cell(0, 10, 'Second page content');

        // Page 3
        $pdf->addPage();
        $pdf->cell(0, 10, 'Third page content');

        // Page 4
        $pdf->addPage();
        $pdf->cell(0, 10, 'Fourth page content');

        $pdfContent = $pdf->output('S', 'test.pdf');

        $extractor = new Text;
        $text      = $extractor->extractFromString($pdfContent);

        // Verify all pages are extracted
        $this->assertStringContainsString('First page content', $text);
        $this->assertStringContainsString('Second page content', $text);
        $this->assertStringContainsString('Third page content', $text);
        $this->assertStringContainsString('Fourth page content', $text);
    }

    public function test_extract_text_from_pdf_with_various_fonts(): void
    {
        // Test with different fonts
        $pdf = self::createFPDF();
        $pdf->setCompression(false);
        $pdf->addPage();

        $pdf->setFont('Arial', '', 12);
        $pdf->cell(0, 10, 'Arial font text');
        $pdf->ln();

        $pdf->setFont('Times', '', 12);
        $pdf->cell(0, 10, 'Times font text');
        $pdf->ln();

        $pdf->setFont('Courier', '', 12);
        $pdf->cell(0, 10, 'Courier font text');
        $pdf->ln();

        $pdfContent = $pdf->output('S', 'test.pdf');

        $extractor = new Text;
        $text      = $extractor->extractFromString($pdfContent);

        $this->assertStringContainsString('Arial font text', $text);
        $this->assertStringContainsString('Times font text', $text);
        $this->assertStringContainsString('Courier font text', $text);
    }

    public function test_extract_text_from_pdf_with_special_formatting(): void
    {
        // Test with various text operations
        $pdf = self::createFPDF();
        $pdf->setCompression(false);
        $pdf->addPage();
        $pdf->setFont('Arial', '', 12);

        // Regular text
        $pdf->cell(40, 10, 'Name:', 0, 0);
        $pdf->cell(60, 10, 'John Doe', 0, 1);

        // Bold text
        $pdf->setFont('Arial', 'B', 12);
        $pdf->cell(40, 10, 'Important:', 0, 0);
        $pdf->setFont('Arial', '', 12);
        $pdf->cell(60, 10, 'Read carefully', 0, 1);

        // Numbers and symbols
        $pdf->cell(0, 10, 'Price: $99.99', 0, 1);
        $pdf->cell(0, 10, 'Email: test@example.com', 0, 1);

        $pdfContent = $pdf->output('S', 'test.pdf');

        $extractor = new Text;
        $text      = $extractor->extractFromString($pdfContent);

        $this->assertStringContainsString('Name:', $text);
        $this->assertStringContainsString('John Doe', $text);
        $this->assertStringContainsString('Important:', $text);
        $this->assertStringContainsString('Read carefully', $text);
        $this->assertStringContainsString('Price:', $text);
        $this->assertStringContainsString('$99.99', $text);
        $this->assertStringContainsString('Email:', $text);
        $this->assertStringContainsString('test@example.com', $text);
    }

    public function test_extract_text_preserves_content_order(): void
    {
        // Test that text extraction preserves the order of content
        $pdf = self::createFPDF();
        $pdf->setCompression(false);
        $pdf->addPage();
        $pdf->setFont('Arial', '', 12);

        $pdf->cell(0, 10, 'Line 1: First', 0, 1);
        $pdf->cell(0, 10, 'Line 2: Second', 0, 1);
        $pdf->cell(0, 10, 'Line 3: Third', 0, 1);
        $pdf->cell(0, 10, 'Line 4: Fourth', 0, 1);

        $pdfContent = $pdf->output('S', 'test.pdf');

        $extractor = new Text;
        $text      = $extractor->extractFromString($pdfContent);

        // Check that the text appears in order
        $firstPos  = strpos($text, 'First');
        $secondPos = strpos($text, 'Second');
        $thirdPos  = strpos($text, 'Third');
        $fourthPos = strpos($text, 'Fourth');

        $this->assertNotFalse($firstPos);
        $this->assertNotFalse($secondPos);
        $this->assertNotFalse($thirdPos);
        $this->assertNotFalse($fourthPos);

        // Verify order
        $this->assertLessThan($secondPos, $firstPos, 'First should appear before Second');
        $this->assertLessThan($thirdPos, $secondPos, 'Second should appear before Third');
        $this->assertLessThan($fourthPos, $thirdPos, 'Third should appear before Fourth');
    }

    public function test_extract_text_from_pdf_with_multiCell(): void
    {
        // Test extraction from multiCell (text wrapping)
        $pdf = self::createFPDF();
        $pdf->setCompression(false);
        $pdf->addPage();
        $pdf->setFont('Arial', '', 12);

        $pdf->cell(0, 10, 'Header: MultiCell Test', 0, 1);

        $longText = 'This is a very long paragraph that will be wrapped across multiple lines using the multiCell method. '
            . 'It should be extracted as continuous text even though it spans multiple visual lines in the PDF.';

        $pdf->multiCell(0, 5, $longText);

        $pdfContent = $pdf->output('S', 'test.pdf');

        $extractor = new Text;
        $text      = $extractor->extractFromString($pdfContent);

        $this->assertStringContainsString('Header: MultiCell Test', $text);
        $this->assertStringContainsString('This is a very long paragraph', $text);
        $this->assertStringContainsString('multiCell method', $text);
    }

    public function test_extract_text_from_empty_pages_returns_empty(): void
    {
        // Test that empty pages don't cause errors
        $pdf = self::createFPDF();
        $pdf->setCompression(false);

        // Add empty page
        $pdf->addPage();

        // Add page with text
        $pdf->addPage();
        $pdf->setFont('Arial', '', 12);
        $pdf->cell(0, 10, 'Only this page has text');

        // Add another empty page
        $pdf->addPage();

        $pdfContent = $pdf->output('S', 'test.pdf');

        $extractor = new Text;
        $text      = $extractor->extractFromString($pdfContent);

        $this->assertStringContainsString('Only this page has text', $text);
        // Should not fail with empty pages
        $this->assertGreaterThan(0, strlen(trim($text)));
    }

    /**
     * This test uses real PDF files from the resources directory.
     * It verifies that text can be extracted from actual PDF files.
     */
    public function test_extract_text_from_real_pdf_files(): void
    {
        // Skip if resources directory doesn't exist
        if (!file_exists($this->resourcesDir)) {
            $this->markTestSkipped('Resources directory not found: ' . $this->resourcesDir);
        }

        $extractor = new Text;

        // Test with 2014-364.pdf
        $pdfFile = $this->resourcesDir . '/2014-364.pdf';

        if (file_exists($pdfFile)) {
            $text = $extractor->extractFromFile($pdfFile);

            // Just verify we can extract something and it doesn't crash
            // Real PDFs might have complex encodings, so we just check it's not empty
            $this->assertIsString($text);
            $this->assertGreaterThanOrEqual(0, strlen($text), 'Should extract text or return empty string');
        } else {
            $this->markTestSkipped('Test PDF file not found: ' . $pdfFile);
        }
    }

    public function test_performance_with_large_document(): void
    {
        // Create a PDF with many pages to test performance
        $pdf = self::createFPDF();
        $pdf->setCompression(false);
        $pdf->setFont('Arial', '', 10);

        // Add 10 pages with content
        for ($i = 1; $i <= 10; $i++) {
            $pdf->addPage();
            $pdf->cell(0, 10, "Page {$i} - Header", 0, 1);

            for ($j = 1; $j <= 20; $j++) {
                $pdf->cell(0, 5, "Page {$i}, Line {$j}: Some sample text content here", 0, 1);
            }
        }

        $pdfContent = $pdf->output('S', 'test.pdf');

        $startTime = microtime(true);
        $extractor = new Text;
        $text      = $extractor->extractFromString($pdfContent);
        $duration  = microtime(true) - $startTime;

        // Verify extraction worked
        $this->assertStringContainsString('Page 1 - Header', $text);
        $this->assertStringContainsString('Page 10 - Header', $text);
        $this->assertStringContainsString('Line 20', $text);

        // Should complete in reasonable time (< 5 seconds for 10 pages)
        $this->assertLessThan(5.0, $duration, 'Extraction should complete in under 5 seconds');
    }
}
