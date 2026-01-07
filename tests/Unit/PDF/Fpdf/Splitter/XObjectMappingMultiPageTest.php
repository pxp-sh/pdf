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
use function dirname;
use function file_exists;
use function glob;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use PXP\PDF\Fpdf\Core\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Core\Object\Base\PDFName;
use PXP\PDF\Fpdf\Core\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Core\Object\Parser\PDFParser;
use PXP\PDF\Fpdf\Core\Stream\PDFStream;
use PXP\PDF\Fpdf\Features\Splitter\PDFMerger;
use PXP\PDF\Fpdf\IO\FileIO;
use Test\TestCase;

/**
 * Test for XObject mapping bug in multi-page merges.
 *
 * This test validates that when merging multiple PDF files, XObjects (images)
 * maintain correct references even when there are ExtGState objects and other
 * resources from previous pages.
 *
 * BUG: When merging page 5 after 4 other pages, XObject references incorrectly
 * point to ExtGState objects from earlier pages instead of the actual Image objects.
 */
final class XObjectMappingMultiPageTest extends TestCase
{
    private FileIO $fileIO;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileIO  = new FileIO(self::getLogger());
        $this->tempDir = sys_get_temp_dir() . '/xobject_multipage_' . uniqid();
        mkdir($this->tempDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');

            foreach ($files as $file) {
                if (is_file($file)) {
                    self::unlink($file);
                }
            }
            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    /**
     * CRITICAL TEST: Verifies XObject references in multi-page merge scenario.
     *
     * This test merges 5 PDF pages where page 5 (page_534.pdf) has images that were
     * incorrectly pointing to ExtGState objects from earlier pages.
     *
     * Expected behavior: All XObjects on page 5 should be Image streams, not ExtGState dictionaries
     *
     * This test MUST FAIL until the XObject mapping bug is fixed.
     */
    public function test_xobject_references_correct_in_multipage_merge(): void
    {
        // Use the actual test files that demonstrate the bug
        $testDir = dirname(__DIR__, 5) . '/var/tmp/tmp_integrity_695b1d79d3964';

        if (!is_dir($testDir)) {
            $this->markTestSkipped("Test directory {$testDir} not available");
        }

        $sourceFiles = [
            $testDir . '/page_530.pdf',
            $testDir . '/page_531.pdf',
            $testDir . '/page_532.pdf',
            $testDir . '/page_533.pdf',
            $testDir . '/page_534.pdf',  // This is the problematic page
        ];

        foreach ($sourceFiles as $file) {
            if (!file_exists($file)) {
                $this->markTestSkipped("Test file {$file} not available");
            }
        }

        // Merge all 5 pages
        $mergedPath = $this->tempDir . '/merged_multipage.pdf';
        $merger     = new PDFMerger($this->fileIO, self::getLogger(), self::getEventDispatcher(), self::getCache());
        $merger->mergeIncremental($sourceFiles, $mergedPath);

        $this->assertFileExists($mergedPath);

        // Parse merged PDF and check page 5 (the problematic page)
        $parser    = new PDFParser(self::getLogger(), self::getCache());
        $mergedDoc = $parser->parseDocumentFromFile($mergedPath, $this->fileIO);

        $page5 = $mergedDoc->getPage(5);
        $this->assertNotNull($page5, 'Page 5 must exist in merged PDF');

        $page5Dict = $page5->getValue();
        $this->assertInstanceOf(PDFDictionary::class, $page5Dict);

        // Get Resources for page 5
        $resources = $page5Dict->getEntry('/Resources');

        if ($resources instanceof PDFReference) {
            $resourcesNode = $mergedDoc->getObject($resources->getObjectNumber());
            $resources     = $resourcesNode->getValue();
        }

        $this->assertInstanceOf(PDFDictionary::class, $resources, 'Page 5 must have Resources');

        // Get XObjects
        $xObjects = $resources->getEntry('/XObject');

        if ($xObjects instanceof PDFReference) {
            $xobjNode = $mergedDoc->getObject($xObjects->getObjectNumber());
            $xObjects = $xobjNode->getValue();
        }

        $this->assertInstanceOf(PDFDictionary::class, $xObjects, 'Page 5 must have XObject dictionary');
        $this->assertGreaterThan(0, count($xObjects->getAllEntries()), 'Page 5 must have XObjects (images)');

        // CRITICAL VALIDATION: Check each XObject to ensure they're Image streams, not ExtGState
        foreach ($xObjects->getAllEntries() as $name => $ref) {
            $this->assertInstanceOf(
                PDFReference::class,
                $ref,
                "XObject {$name} must be a reference",
            );

            $xobjNode = $mergedDoc->getObject($ref->getObjectNumber());
            $this->assertNotNull(
                $xobjNode,
                "XObject {$name} reference (object {$ref->getObjectNumber()}) must resolve",
            );

            $xobj = $xobjNode->getValue();

            // CRITICAL: XObject MUST be a Stream (contains image data), NOT a Dictionary (ExtGState)
            $this->assertInstanceOf(
                PDFStream::class,
                $xobj,
                "CRITICAL BUG: XObject {$name} (page 5) points to " . $xobj::class . ' instead of PDFStream. ' .
                'Expected Image XObject, got ' . $this->describeObject($xobj, $mergedDoc) . '. ' .
                'This indicates XObject reference points to wrong object (likely ExtGState from earlier page).',
            );

            // Verify it's an Image XObject, not just any stream
            $dict = $xobj->getDictionary();
            $type = $dict->getEntry('/Type');
            $this->assertInstanceOf(PDFName::class, $type, "XObject {$name} must have /Type");
            $this->assertEquals(
                'XObject',
                $type->getName(),
                "XObject {$name} /Type must be 'XObject', got '{$type->getName()}'",
            );

            $subtype = $dict->getEntry('/Subtype');
            $this->assertInstanceOf(PDFName::class, $subtype, "XObject {$name} must have /Subtype");
            $this->assertEquals(
                'Image',
                $subtype->getName(),
                "XObject {$name} /Subtype must be 'Image' (not 'Form'), got '{$subtype->getName()}'",
            );

            // Verify image properties exist
            $this->assertNotNull($dict->getEntry('/Width'), "Image XObject {$name} must have /Width");
            $this->assertNotNull($dict->getEntry('/Height'), "Image XObject {$name} must have /Height");
        }
    }

    /**
     * Test that original page_534.pdf has correct Image XObjects.
     *
     * This validates our test data is correct before testing the merge.
     */
    public function test_original_page534_has_image_xobjects(): void
    {
        $testFile = dirname(__DIR__, 5) . '/var/tmp/tmp_integrity_695b1d79d3964/page_534.pdf';

        if (!file_exists($testFile)) {
            $this->markTestSkipped("Test file {$testFile} not available");
        }

        $parser = new PDFParser(self::getLogger(), self::getCache());
        $doc    = $parser->parseDocumentFromFile($testFile, $this->fileIO);

        $page     = $doc->getPage(1);
        $pageDict = $page->getValue();

        $resources = $pageDict->getEntry('/Resources');

        if ($resources instanceof PDFReference) {
            $resourcesNode = $doc->getObject($resources->getObjectNumber());
            $resources     = $resourcesNode->getValue();
        }

        $xObjects = $resources->getEntry('/XObject');

        if ($xObjects instanceof PDFReference) {
            $xobjNode = $doc->getObject($xObjects->getObjectNumber());
            $xObjects = $xobjNode->getValue();
        }

        $this->assertInstanceOf(PDFDictionary::class, $xObjects);
        $this->assertCount(3, $xObjects->getAllEntries(), 'page_534.pdf should have 3 XObjects (I1, I2, I3)');

        // Verify all are Image XObjects
        foreach ($xObjects->getAllEntries() as $name => $ref) {
            $xobjNode = $doc->getObject($ref->getObjectNumber());
            $xobj     = $xobjNode->getValue();

            $this->assertInstanceOf(PDFStream::class, $xobj, "Original {$name} must be a Stream");

            $dict    = $xobj->getDictionary();
            $type    = $dict->getEntry('/Type');
            $subtype = $dict->getEntry('/Subtype');

            $this->assertEquals('XObject', $type->getName(), "Original {$name} must be /Type /XObject");
            $this->assertEquals('Image', $subtype->getName(), "Original {$name} must be /Subtype /Image");
        }
    }

    /**
     * Helper to describe an object for error messages.
     */
    private function describeObject($obj, $doc): string
    {
        if ($obj instanceof PDFDictionary && !($obj instanceof PDFStream)) {
            $type = $obj->getEntry('/Type');

            if ($type instanceof PDFName) {
                return 'Dictionary with /Type = ' . $type->getName();
            }

            return 'Dictionary (no /Type)';
        }

        if ($obj instanceof PDFStream) {
            $dict = $obj->getDictionary();
            $type = $dict->getEntry('/Type');

            if ($type instanceof PDFName) {
                return 'Stream with /Type = ' . $type->getName();
            }

            return 'Stream (no /Type)';
        }

        return $obj::class;
    }
}
