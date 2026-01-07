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
namespace Test\Feature;

use function abs;
use function dirname;
use function escapeshellarg;
use function exec;
use function file_exists;
use function glob;
use function implode;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function round;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use PXP\PDF\Fpdf\Core\Object\Parser\PDFParser;
use PXP\PDF\Fpdf\Features\Splitter\PDFMerger;
use PXP\PDF\Fpdf\Features\Splitter\PDFSplitter;
use PXP\PDF\Fpdf\IO\FileIO;
use PXP\PDF\PDFDictionary;
use PXP\PDF\PDFReference;
use PXP\PDF\PDFStream;
use Test\TestCase;

/**
 * Test for table content preservation during merge operations.
 *
 * This test reproduces a bug where table columns are lost when merging
 * extracted PDF pages that contain Form XObjects with nested resources.
 *
 * @see docs/TABLE_CONTENT_LOSS_ANALYSIS.md
 */
final class TableContentPreservationTest extends TestCase
{
    private string $tempDir;
    private FileIO $fileIO;
    private string $sourcePdf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/pdf_table_test_' . uniqid();
        @mkdir($this->tempDir, 0o777, true);

        $this->fileIO    = new FileIO(self::getLogger());
        $this->sourcePdf = dirname(__DIR__) . '/resources/PDF/input/23-grande.pdf';

        if (!file_exists($this->sourcePdf)) {
            $this->markTestSkipped('Source PDF 23-grande.pdf not available');
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');

            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    /**
     * Test that table content is fully preserved when merging extracted pages.
     *
     * This test reproduces the bug where Form XObject content is truncated
     * during merge, causing table columns to disappear.
     *
     * Steps:
     * 1. Extract page 533 individually (reference/correct version)
     * 2. Extract pages 530-534 and merge them
     * 3. Extract page 1 from the merged PDF (corresponds to page 533)
     * 4. Compare text content - should be identical
     * 5. Compare Form XObject stream sizes - should be similar
     */
    public function test_table_content_preserved_during_merge(): void
    {
        // Step 1: Extract page 533 as reference (this is known to work correctly)
        $page533Path = $this->tempDir . '/page_533_reference.pdf';
        $splitter    = new PDFSplitter($this->sourcePdf, $this->fileIO, self::getLogger());
        $splitter->extractPage(533, $page533Path);

        $this->assertFileExists($page533Path);

        // Step 2: Extract pages 530-534 individually
        $extractedPages = [];

        for ($pageNum = 530; $pageNum <= 534; $pageNum++) {
            $pagePath = $this->tempDir . "/page_{$pageNum}.pdf";
            $splitter->extractPage($pageNum, $pagePath);
            $this->assertFileExists($pagePath);
            $extractedPages[] = $pagePath;
        }

        // Step 3: Merge the extracted pages
        $mergedPath = $this->tempDir . '/merged_pages_530_534.pdf';
        $merger     = new PDFMerger(
            $this->fileIO,
            self::getLogger(),
            self::getEventDispatcher(),
            self::getCache(),
        );
        $merger->mergeIncremental($extractedPages, $mergedPath);

        $this->assertFileExists($mergedPath);

        // Step 4: Extract the text content from both PDFs
        $text533    = $this->extractTextFromPdf($page533Path);
        $textMerged = $this->extractTextFromPdf($mergedPath, pageNum: 4); // Page 533 is 4th in merged (0-indexed: page 3)

        // Debug output
        self::getLogger()->info('Text from reference page 533:', [
            'length'  => strlen($text533),
            'preview' => substr($text533, 0, 500),
        ]);

        self::getLogger()->info('Text from merged PDF (page 4):', [
            'length'  => strlen($textMerged),
            'preview' => substr($textMerged, 0, 500),
        ]);

        // Assert: Text content should be similar
        // The reference PDF should contain the column headers that are missing in the broken version
        $this->assertStringContainsString(
            'TIPO DE SISTEMA',
            $text533,
            'Reference PDF should contain "TIPO DE SISTEMA" column header',
        );
        $this->assertStringContainsString(
            'TIPO DE INSTALAÇÃO',
            $text533,
            'Reference PDF should contain "TIPO DE INSTALAÇÃO" column header',
        );
        $this->assertStringContainsString(
            'INSTALAÇÕES',
            $text533,
            'Reference PDF should contain "INSTALAÇÕES" column header',
        );

        // THIS IS THE FAILING ASSERTION - merged PDF is missing these columns
        $this->assertStringContainsString(
            'TIPO DE SISTEMA',
            $textMerged,
            'Merged PDF should contain "TIPO DE SISTEMA" column header (BUG: This is missing!)',
        );
        $this->assertStringContainsString(
            'TIPO DE INSTALAÇÃO',
            $textMerged,
            'Merged PDF should contain "TIPO DE INSTALAÇÃO" column header (BUG: This is missing!)',
        );
        $this->assertStringContainsString(
            'INSTALAÇÕES',
            $textMerged,
            'Merged PDF should contain "INSTALAÇÕES" column header (BUG: This is missing!)',
        );

        // Step 5: Compare Form XObject stream sizes
        $parser = new PDFParser(self::getLogger(), self::getCache());

        $formXObjSize533    = $this->getFormXObjectStreamSize($page533Path, $parser);
        $formXObjSizeMerged = $this->getFormXObjectStreamSize($mergedPath, $parser);

        self::getLogger()->info('Form XObject stream sizes:', [
            'reference_page_533' => $formXObjSize533,
            'merged_pdf'         => $formXObjSizeMerged,
            'difference'         => abs($formXObjSize533 - $formXObjSizeMerged),
            'loss_percentage'    => $formXObjSize533 > 0
                ? round((($formXObjSize533 - $formXObjSizeMerged) / $formXObjSize533) * 100, 2) . '%'
                : '0%',
        ]);

        // Assert: Stream sizes should be close (within 10% tolerance)
        // A significant difference indicates content loss
        $tolerance = $formXObjSize533 * 0.1; // 10% tolerance
        $this->assertEqualsWithDelta(
            $formXObjSize533,
            $formXObjSizeMerged,
            $tolerance,
            sprintf(
                'Form XObject stream size should be similar. Expected ~%d bytes, got %d bytes (%.1f%% loss)',
                $formXObjSize533,
                $formXObjSizeMerged,
                $formXObjSize533 > 0 ? (($formXObjSize533 - $formXObjSizeMerged) / $formXObjSize533) * 100 : 0,
            ),
        );
    }

    /**
     * Extract text content from a PDF using pdftotext.
     */
    private function extractTextFromPdf(string $pdfPath, int $pageNum = 1): string
    {
        if (!file_exists($pdfPath)) {
            return '';
        }

        // Use pdftotext to extract text
        $output    = [];
        $returnVar = 0;
        exec(
            sprintf('pdftotext -f %d -l %d %s -', $pageNum, $pageNum, escapeshellarg($pdfPath)),
            $output,
            $returnVar,
        );

        if ($returnVar !== 0) {
            // Fallback: try without page specification
            exec(sprintf('pdftotext %s -', escapeshellarg($pdfPath)), $output, $returnVar);
        }

        return implode("\n", $output);
    }

    /**
     * Get the size of the Form XObject stream in a PDF.
     *
     * Returns the decoded stream length of the first Form XObject found (TPL*).
     */
    private function getFormXObjectStreamSize(string $pdfPath, PDFParser $parser): int
    {
        $doc  = $parser->parseDocumentFromFile($pdfPath, $this->fileIO);
        $page = $doc->getPage(1);

        if ($page === null) {
            return 0;
        }

        $pageDict     = $page->getValue();
        $resourcesRef = $pageDict->getEntry('/Resources');

        $resources = $resourcesRef;

        if ($resourcesRef instanceof PDFReference) {
            $resourcesNode = $doc->getObject($resourcesRef->getObjectNumber());

            if ($resourcesNode !== null) {
                $resources = $resourcesNode->getValue();
            }
        }

        if (!$resources instanceof PDFDictionary) {
            return 0;
        }

        $xobjects = $resources->getEntry('/XObject');

        if ($xobjects instanceof PDFReference) {
            $xobjNode = $doc->getObject($xobjects->getObjectNumber());

            if ($xobjNode !== null) {
                $xobjects = $xobjNode->getValue();
            }
        }

        if (!$xobjects instanceof PDFDictionary) {
            return 0;
        }

        // Find the Form XObject (TPL*)
        foreach ($xobjects->getAllEntries() as $name => $ref) {
            if (str_starts_with($name, '/TPL')) {
                if ($ref instanceof PDFReference) {
                    $xobjNode = $doc->getObject($ref->getObjectNumber());

                    if ($xobjNode !== null) {
                        $xobj = $xobjNode->getValue();

                        if ($xobj instanceof PDFStream) {
                            $subtype = $xobj->getEntry('/Subtype');

                            if ((string) $subtype === '/Form') {
                                return strlen($xobj->getDecodedData());
                            }
                        }
                    }
                }
            }
        }

        return 0;
    }
}
