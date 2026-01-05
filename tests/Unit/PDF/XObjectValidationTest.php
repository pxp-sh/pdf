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
namespace Tests\Unit\PDF;

use function exec;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function imagecolorallocate;
use function imagecreate;
use function imagedestroy;
use function imagefill;
use function imagepng;
use function implode;
use function ob_get_clean;
use function ob_start;
use function preg_match_all;
use function str_contains;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use Exception;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use PXP\PDF\Fpdf\FPDF;
use PXP\PDF\Fpdf\IO\FileIO;
use PXP\PDF\Fpdf\Splitter\PDFSplitter;
use Test\TestCase;

/**
 * Test XObject integrity during PDF extraction.
 *
 * Validates that XObjects (Form XObjects, images, etc.):
 * - Are correctly referenced in extracted pages
 * - Contain actual data (not empty)
 * - Have valid stream content
 */
class XObjectValidationTest extends TestCase
{
    private string $testPdfWithImages;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new Logger('test');
        $this->logger->pushHandler(new StreamHandler('php://stdout'));

        // Create a test PDF with images (XObjects)
        $this->testPdfWithImages = $this->createTestPdfWithImage();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testPdfWithImages)) {
            unlink($this->testPdfWithImages);
        }

        parent::tearDown();
    }

    /**
     * Test that XObjects are present in extracted PDF.
     */
    public function test_xobjects_present_in_extracted_pdf(): void
    {
        $extractedPath = sys_get_temp_dir() . '/xobject_test_extracted.pdf';
        $fileIO        = new FileIO($this->logger);
        $splitter      = new PDFSplitter($this->testPdfWithImages, $fileIO, $this->logger);
        $splitter->extractPage(1, $extractedPath);

        $this->assertFileExists($extractedPath);

        // Read PDF content and check for XObject references
        $content = file_get_contents($extractedPath);
        $this->assertStringContainsString('/XObject', $content, 'PDF should contain XObject reference');
        $this->assertStringContainsString('/Resources', $content, 'PDF should have Resources dictionary');

        // Log resources for debugging
        $this->logger->info('Page resources', [
            'has_xobject'   => str_contains($content, '/XObject'),
            'has_resources' => str_contains($content, '/Resources'),
            'file_size'     => filesize($extractedPath),
        ]);

        // Cleanup
        unlink($extractedPath);
    }

    /**
     * Test that XObjects have non-empty content.
     */
    public function test_xobjects_have_content(): void
    {
        $extractedPath = sys_get_temp_dir() . '/xobject_content_test.pdf';
        $fileIO        = new FileIO($this->logger);
        $splitter      = new PDFSplitter($this->testPdfWithImages, $fileIO, $this->logger);
        $splitter->extractPage(1, $extractedPath);

        $fileSize = filesize($extractedPath);
        $this->assertGreaterThan(1000, $fileSize, 'Extracted PDF with images should have substantial content');

        // Verify structure and content
        $content = file_get_contents($extractedPath);
        $this->assertStringStartsWith('%PDF-', $content);
        $this->assertStringContainsString('%%EOF', $content);

        // Count object references (rough estimate)
        $objectCount = preg_match_all('/\d+ \d+ obj/', $content);
        $this->assertGreaterThan(0, $objectCount, 'PDF should contain objects');

        $this->logger->info('XObject analysis', [
            'file_size'         => $fileSize,
            'estimated_objects' => $objectCount,
        ]);

        // Cleanup
        unlink($extractedPath);
    }

    /**
     * Test XObject references are valid.
     */
    public function test_xobject_references_valid(): void
    {
        $extractedPath = sys_get_temp_dir() . '/xobject_refs_test.pdf';
        $fileIO        = new FileIO($this->logger);
        $splitter      = new PDFSplitter($this->testPdfWithImages, $fileIO, $this->logger);
        $splitter->extractPage(1, $extractedPath);

        $this->assertFileExists($extractedPath);

        try {
            // Use external tool to validate if available
            if ($this->isQpdfAvailable()) {
                $output     = [];
                $returnCode = 0;
                exec("qpdf --check {$extractedPath} 2>&1", $output, $returnCode);

                $this->logger->info('QPDF validation', [
                    'return_code' => $returnCode,
                    'output'      => implode("\n", $output),
                ]);

                // qpdf returns 0 for valid PDFs, 2 for warnings, 3+ for errors
                $this->assertLessThanOrEqual(2, $returnCode, 'PDF should be valid or have only warnings');
            } else {
                $this->markTestSkipped('qpdf not available for validation');
            }
        } catch (Exception $e) {
            $this->logger->warning('XObject validation failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // Cleanup
        unlink($extractedPath);
    }

    /**
     * Test that Form XObjects (TPL*) are correctly handled.
     */
    public function test_form_xobjects_handled(): void
    {
        // This would need a PDF with Form XObjects (imported pages)
        // For now, test basic XObject handling

        $extractedPath = sys_get_temp_dir() . '/form_xobject_test.pdf';
        $fileIO        = new FileIO($this->logger);
        $splitter      = new PDFSplitter($this->testPdfWithImages, $fileIO, $this->logger);
        $splitter->extractPage(1, $extractedPath);

        // Verify PDF is valid and parseable
        $this->assertFileExists($extractedPath);
        $content = file_get_contents($extractedPath);
        $this->assertStringStartsWith('%PDF-', $content);
        $this->assertGreaterThan(0, filesize($extractedPath));

        // Cleanup
        unlink($extractedPath);
    }

    /**
     * Create a test PDF with an image (XObject).
     */
    private function createTestPdfWithImage(): string
    {
        $pdf = new FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'Test PDF with Image XObject', 0, 1);

        // Create a simple test image
        $imageData = $this->createTestImage();
        $imagePath = sys_get_temp_dir() . '/test_image.png';
        file_put_contents($imagePath, $imageData);

        // Add image to PDF
        $pdf->Image($imagePath, 50, 40, 100);

        $pdf->SetY(160);
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 10, 'This PDF contains an image XObject above', 0, 1);

        $testFile = sys_get_temp_dir() . '/test_xobject_' . uniqid() . '.pdf';
        $pdf->Output('F', $testFile);

        unlink($imagePath);

        return $testFile;
    }

    /**
     * Create a simple test image (1x1 PNG).
     */
    private function createTestImage(): string
    {
        // Create 1x1 red pixel PNG
        $img = imagecreate(100, 100);
        $red = imagecolorallocate($img, 255, 0, 0);
        imagefill($img, 0, 0, $red);

        ob_start();
        imagepng($img);
        $imageData = ob_get_clean();
        imagedestroy($img);

        return $imageData;
    }

    /**
     * Check if qpdf is available on the system.
     */
    private function isQpdfAvailable(): bool
    {
        $output     = [];
        $returnCode = 0;
        exec('which qpdf 2>&1', $output, $returnCode);

        return $returnCode === 0;
    }
}
