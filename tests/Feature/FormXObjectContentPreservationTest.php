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
use function gettype;
use function glob;
use function implode;
use function is_dir;
use function is_file;
use function is_object;
use function mkdir;
use function preg_replace;
use function rmdir;
use function round;
use function sprintf;
use function strlen;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use PXP\PDF\Fpdf\Core\Object\Parser\PDFParser;
use PXP\PDF\Fpdf\Features\Splitter\PDFSplitter;
use PXP\PDF\Fpdf\IO\FileIO;
use PXP\PDF\PDFDictionary;
use PXP\PDF\PDFReference;
use PXP\PDF\PDFStream;
use Test\TestCase;

/**
 * Test for Form XObject content preservation during page extraction.
 *
 * This test verifies that Form XObject streams are fully copied when
 * extracting pages, without truncation or data loss.
 *
 * @see docs/TABLE_CONTENT_LOSS_FINAL_ANALYSIS.md
 */
final class FormXObjectContentPreservationTest extends TestCase
{
    private string $tempDir;
    private FileIO $fileIO;
    private string $sourcePdf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/form_xobj_test_' . uniqid();
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

            if ($files !== false) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
            @rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    /**
     * Test that Form XObject streams are fully preserved during page extraction.
     *
     * This is a DIRECT test of the bug: Form XObject streams are being truncated
     * when pages are extracted, losing approximately 37% of content.
     *
     * Steps:
     * 1. Parse original PDF and get Form XObject stream size from page 533
     * 2. Extract page 533 using PDFSplitter
     * 3. Parse extracted PDF and get Form XObject stream size
     * 4. Assert sizes are equal (or very close)
     *
     * @group form-xobject
     */
    public function test_form_xobject_stream_preserved_during_extraction(): void
    {
        $parser = new PDFParser(self::getLogger(), self::getCache());

        // Step 1: Get Form XObject size from original PDF (page 533)
        $originalDoc  = $parser->parseDocumentFromFile($this->sourcePdf, $this->fileIO);
        $originalPage = $originalDoc->getPage(533);

        $this->assertNotNull($originalPage, 'Page 533 must exist in source PDF');

        $originalFormXObjSize = $this->getFormXObjectStreamSize($originalPage, $originalDoc);

        self::getLogger()->info('Original Form XObject stream size', [
            'page'       => 533,
            'size_bytes' => $originalFormXObjSize,
        ]);

        $this->assertGreaterThan(
            0,
            $originalFormXObjSize,
            'Original page should have a Form XObject with content',
        );

        // Step 2: Extract page 533
        $extractedPath = $this->tempDir . '/page_533_extracted.pdf';
        $splitter      = new PDFSplitter($this->sourcePdf, $this->fileIO, self::getLogger());
        $splitter->extractPage(533, $extractedPath);

        $this->assertFileExists($extractedPath);

        // Step 3: Get Form XObject size from extracted PDF
        $extractedDoc  = $parser->parseDocumentFromFile($extractedPath, $this->fileIO);
        $extractedPage = $extractedDoc->getPage(1);

        $this->assertNotNull($extractedPage, 'Extracted PDF should have page 1');

        $extractedFormXObjSize = $this->getFormXObjectStreamSize($extractedPage, $extractedDoc);

        self::getLogger()->info('Extracted Form XObject stream size', [
            'page'       => 1,
            'size_bytes' => $extractedFormXObjSize,
        ]);

        $this->assertGreaterThan(
            0,
            $extractedFormXObjSize,
            'Extracted page should have a Form XObject with content',
        );

        // Step 4: Assert sizes are equal (within small tolerance for compression differences)
        $sizeDifference = abs($originalFormXObjSize - $extractedFormXObjSize);
        $percentageLoss = ($originalFormXObjSize > 0)
            ? (($sizeDifference / $originalFormXObjSize) * 100)
            : 0;

        self::getLogger()->warning('Form XObject size comparison', [
            'original_size'    => $originalFormXObjSize,
            'extracted_size'   => $extractedFormXObjSize,
            'difference_bytes' => $sizeDifference,
            'loss_percentage'  => round($percentageLoss, 2) . '%',
        ]);

        // Allow 5% tolerance for compression differences
        $maxAllowedDifference = (int) ($originalFormXObjSize * 0.05);

        $this->assertLessThanOrEqual(
            $maxAllowedDifference,
            $sizeDifference,
            sprintf(
                'Form XObject stream size should be preserved. Expected ~%d bytes, got %d bytes (%.1f%% loss). ' .
                'This indicates content truncation during extraction.',
                $originalFormXObjSize,
                $extractedFormXObjSize,
                $percentageLoss,
            ),
        );

        // Also verify via text extraction
        $this->verifyTextContentPreserved($extractedPath);
    }

    /**
     * Get the decoded stream size of the first Form XObject in a page.
     */
    private function getFormXObjectStreamSize($page, $doc): int
    {
        $pageDict = $page->getValue();

        if (!$pageDict instanceof PDFDictionary) {
            self::getLogger()->warning('Page is not a dictionary', ['type' => $pageDict::class]);

            return 0;
        }

        $resourcesRef = $pageDict->getEntry('/Resources');

        if ($resourcesRef === null) {
            self::getLogger()->warning('No /Resources entry in page');

            return 0;
        }

        $resources = $resourcesRef;

        if ($resourcesRef instanceof PDFReference) {
            $resourcesNode = $doc->getObject($resourcesRef->getObjectNumber());

            if ($resourcesNode !== null) {
                $resources = $resourcesNode->getValue();
            }
        }

        if (!$resources instanceof PDFDictionary) {
            self::getLogger()->warning('Resources is not a dictionary', ['type' => $resources::class]);

            return 0;
        }

        $xobjects = $resources->getEntry('/XObject');

        if ($xobjects === null) {
            self::getLogger()->warning('No /XObject entry in Resources');

            return 0;
        }

        if ($xobjects instanceof PDFReference) {
            $xobjNode = $doc->getObject($xobjects->getObjectNumber());

            if ($xobjNode !== null) {
                $xobjects = $xobjNode->getValue();
            }
        }

        if (!$xobjects instanceof PDFDictionary) {
            self::getLogger()->warning('XObjects is not a dictionary', ['type' => is_object($xobjects) ? $xobjects::class : gettype($xobjects)]);

            return 0;
        }

        // Find Form XObjects (TPL* naming convention or any with /Subtype /Form)
        foreach ($xobjects->getAllEntries() as $name => $ref) {
            self::getLogger()->debug('Checking XObject', ['name' => $name, 'type' => $ref::class]);

            $xobj = $ref;

            if ($ref instanceof PDFReference) {
                $xobjNode = $doc->getObject($ref->getObjectNumber());

                if ($xobjNode !== null) {
                    $xobj = $xobjNode->getValue();
                } else {
                    self::getLogger()->warning('Could not dereference XObject', ['name' => $name, 'obj_num' => $ref->getObjectNumber()]);

                    continue;
                }
            }

            self::getLogger()->debug('XObject value type', ['name' => $name, 'type' => $xobj::class]);

            if ($xobj instanceof PDFStream) {
                $dict    = $xobj->getDictionary();
                $subtype = $dict->getEntry('/Subtype');
                self::getLogger()->debug('Stream subtype', ['name' => $name, 'subtype' => (string) $subtype]);

                if ((string) $subtype === '/Form') {
                    $streamData = $xobj->getDecodedData();
                    $size       = strlen($streamData);
                    self::getLogger()->info('Found Form XObject', ['name' => $name, 'size' => $size]);

                    return $size;
                }
            }
        }

        self::getLogger()->warning('No Form XObject found in page');

        return 0;
    }

    /**
     * Verify text content is preserved (specifically the table columns).
     */
    private function verifyTextContentPreserved(string $pdfPath): void
    {
        $output    = [];
        $returnVar = 0;
        exec(sprintf('pdftotext %s -', escapeshellarg($pdfPath)), $output, $returnVar);

        if ($returnVar !== 0) {
            self::getLogger()->warning('pdftotext failed, skipping text verification');

            return;
        }

        $text = implode("\n", $output);

        // Remove whitespace for comparison
        $textNormalized = preg_replace('/\s+/', '', $text);

        // These are the column headers that should be present
        $expectedColumns = [
            'TIPODESISTEMA',
            'TIPODEINSTALAÇÃO',
            'INSTALAÇÕES',
        ];

        foreach ($expectedColumns as $column) {
            $this->assertStringContainsString(
                $column,
                $textNormalized,
                "Extracted PDF should contain column header: {$column}",
            );
        }
    }
}
