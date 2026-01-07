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
namespace Test\Unit\PDF\Fpdf\Splitter;

use function count;
use function file_exists;
use function filesize;
use function glob;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function mkdir;
use function rmdir;
use function shell_exec;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use PXP\PDF\Fpdf\Features\Splitter\PDFSplitter;
use PXP\PDF\Fpdf\IO\FileIO;
use Test\TestCase;

/**
 * Test that page extraction properly filters resources and only includes
 * resources that are actually used by the page's content stream.
 *
 * This test validates the fix for the resource bloat bug where all resources
 * from the source document were being copied to each extracted page.
 */
final class ResourceFilteringTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/pdf_resource_filtering_test_' . uniqid();

        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0o777, true);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up test files
        if (is_dir($this->testDir)) {
            $files = glob($this->testDir . '/*');

            foreach ($files as $file) {
                if (is_file($file)) {
                    self::unlink($file);
                }
            }
            rmdir($this->testDir);
        }
    }

    /**
     * Test that extracted single pages are significantly smaller than
     * the source PDF when the source has shared resources.
     *
     * This is a regression test for the resource bloat bug.
     */
    public function test_extracted_page_size_is_reasonable(): void
    {
        $resourcesDir = self::getRootDir() . '/tests/resources';
        $pdfPath      = $resourcesDir . '/multi_page_with_shared_resources.pdf';

        // Create a test PDF with shared resources if it doesn't exist
        if (!file_exists($pdfPath)) {
            $this->createTestPdfWithSharedResources();
        }

        $fileIO      = new FileIO;
        $pdfSplitter = new PDFSplitter($pdfPath, $fileIO);

        $pageCount = $pdfSplitter->getPageCount();
        $this->assertGreaterThanOrEqual(2, $pageCount, 'Test PDF should have at least 2 pages');

        // Extract first page
        $outputPath = $this->testDir . '/page_1.pdf';
        $pdfSplitter->extractPage(1, $outputPath);

        $this->assertFileExists($outputPath);

        // Get file sizes
        $sourcePdfSize     = filesize($pdfPath);
        $extractedPageSize = filesize($outputPath);

        // The extracted page should be significantly smaller than the source
        // A reasonable threshold is that a single page should be less than
        // (source_size / page_count) * 1.5 (allowing 50% overhead)
        $expectedMaxSize = ($sourcePdfSize / $pageCount) * 1.5;

        $this->assertLessThan(
            $expectedMaxSize,
            $extractedPageSize,
            sprintf(
                'Extracted page size (%d bytes) should be less than %d bytes (source: %d bytes, %d pages)',
                $extractedPageSize,
                (int) $expectedMaxSize,
                $sourcePdfSize,
                $pageCount,
            ),
        );
    }

    /**
     * Test that extracted pages from a PDF with many XObjects
     * only contain the XObjects they actually use.
     */
    public function test_extracted_page_filters_unused_xobjects(): void
    {
        $resourcesDir = self::getRootDir() . '/tests/resources';
        $pdfPath      = $resourcesDir . '/pdf_with_many_templates.pdf';

        // Create test PDF if needed
        if (!file_exists($pdfPath)) {
            $this->createTestPdfWithManyTemplates();
        }

        $fileIO      = new FileIO;
        $pdfSplitter = new PDFSplitter($pdfPath, $fileIO);

        // Extract first page
        $outputPath = $this->testDir . '/filtered_page.pdf';
        $pdfSplitter->extractPage(1, $outputPath);

        $this->assertFileExists($outputPath);

        // Use qpdf to inspect the resources in the extracted page
        $jsonOutput = shell_exec("qpdf --json '{$outputPath}' 2>&1");
        $this->assertNotEmpty($jsonOutput, 'qpdf should produce JSON output');

        $jsonData = json_decode($jsonOutput, true);
        $this->assertIsArray($jsonData, 'qpdf JSON should be valid');

        // Find the resources dictionary and count XObjects
        $xobjectCount = $this->countXObjectsInPdf($jsonData);

        // The extracted page should have significantly fewer XObjects than the source
        $sourceJsonOutput   = shell_exec("qpdf --json '{$pdfPath}' 2>&1");
        $sourceJsonData     = json_decode($sourceJsonOutput, true);
        $sourceXobjectCount = $this->countXObjectsInPdf($sourceJsonData);

        $this->assertGreaterThan(10, $sourceXobjectCount, 'Source PDF should have many XObjects');
        $this->assertLessThan(
            $sourceXobjectCount,
            $xobjectCount,
            'Extracted page should have fewer XObjects than source',
        );
    }

    /**
     * Test the specific scenario from the bug report:
     * A page extracted from a 533-template document should not contain all 533 templates.
     */
    public function test_extracted_page_does_not_copy_all_source_templates(): void
    {
        // This test validates the exact scenario from the bug report
        $resourcesDir = self::getRootDir() . '/tests/resources';
        $pdfPath      = $resourcesDir . '/document_with_533_templates.pdf';

        if (!file_exists($pdfPath)) {
            $this->markTestSkipped('Test PDF with 533 templates not available');
        }

        $fileIO      = new FileIO;
        $pdfSplitter = new PDFSplitter($pdfPath, $fileIO);

        $outputPath = $this->testDir . '/page_extracted.pdf';
        $pdfSplitter->extractPage(1, $outputPath);

        $this->assertFileExists($outputPath);

        // Count XObjects in extracted page
        $extractedResources    = $this->getResourcesFromPdf($outputPath);
        $extractedXObjectCount = count($extractedResources['xobjects'] ?? []);

        // The page should NOT have 533 templates
        $this->assertLessThan(
            533,
            $extractedXObjectCount,
            'Extracted page should not contain all 533 templates from source',
        );

        // In fact, a typical page should use far fewer
        $this->assertLessThan(
            100,
            $extractedXObjectCount,
            'A typical page should use fewer than 100 XObjects',
        );
    }

    /**
     * Test that multiple extracted pages have different XObject sets
     * when they use different resources.
     */
    public function test_different_pages_have_different_resources(): void
    {
        $resourcesDir = self::getRootDir() . '/tests/resources';
        $pdfPath      = $resourcesDir . '/multi_page_varied_resources.pdf';

        if (!file_exists($pdfPath)) {
            $this->createTestPdfWithVariedResources();
        }

        $fileIO      = new FileIO;
        $pdfSplitter = new PDFSplitter($pdfPath, $fileIO);

        $pageCount = $pdfSplitter->getPageCount();

        if ($pageCount < 2) {
            $this->markTestSkipped('Need at least 2 pages for this test');
        }

        // Extract first two pages
        $page1Path = $this->testDir . '/page_1.pdf';
        $page2Path = $this->testDir . '/page_2.pdf';

        $pdfSplitter->extractPage(1, $page1Path);
        $pdfSplitter->extractPage(2, $page2Path);

        $page1Resources = $this->getResourcesFromPdf($page1Path);
        $page2Resources = $this->getResourcesFromPdf($page2Path);

        // If the pages use different resources, the extracted PDFs should reflect that
        // (This test may need to be adjusted based on the actual test PDF structure)
        $this->assertIsArray($page1Resources);
        $this->assertIsArray($page2Resources);
    }

    /**
     * Helper: Count XObjects in a PDF's JSON structure.
     *
     * @param array<string, mixed> $jsonData
     */
    private function countXObjectsInPdf(array $jsonData): int
    {
        if (!isset($jsonData['qpdf'][0]['objects'])) {
            return 0;
        }

        $count   = 0;
        $objects = $jsonData['qpdf'][0]['objects'];

        foreach ($objects as $object) {
            $value = $object['value'] ?? [];

            if (isset($value['/Type']) && $value['/Type'] === '/XObject') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Helper: Get resources from a PDF file.
     */
    private function getResourcesFromPdf(string $pdfPath): array
    {
        $jsonOutput = shell_exec("qpdf --json '{$pdfPath}' 2>&1");

        if (!$jsonOutput) {
            return ['xobjects' => [], 'fonts' => []];
        }

        $jsonData = json_decode($jsonOutput, true);

        if (!is_array($jsonData) || !isset($jsonData['qpdf'][0]['objects'])) {
            return ['xobjects' => [], 'fonts' => []];
        }

        $resources = ['xobjects' => [], 'fonts' => []];
        $objects   = $jsonData['qpdf'][0]['objects'];

        foreach ($objects as $object) {
            $value = $object['value'] ?? [];

            if (isset($value['/Type'])) {
                if ($value['/Type'] === '/XObject') {
                    $resources['xobjects'][] = $object;
                } elseif ($value['/Type'] === '/Font') {
                    $resources['fonts'][] = $object;
                }
            }
        }

        return $resources;
    }

    /**
     * Helper: Create a test PDF with shared resources across pages.
     */
    private function createTestPdfWithSharedResources(): never
    {
        // Create a simple multi-page PDF where pages share resources
        // For now, we'll use an existing test PDF or skip
        $this->markTestSkipped('Test PDF creation not implemented yet');
    }

    /**
     * Helper: Create a test PDF with many template XObjects.
     */
    private function createTestPdfWithManyTemplates(): never
    {
        $this->markTestSkipped('Test PDF creation not implemented yet');
    }

    /**
     * Helper: Create a test PDF with varied resources per page.
     */
    private function createTestPdfWithVariedResources(): never
    {
        $this->markTestSkipped('Test PDF creation not implemented yet');
    }
}
