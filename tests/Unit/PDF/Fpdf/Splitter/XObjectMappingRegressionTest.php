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

use function dirname;
use function file_exists;
use function glob;
use function is_dir;
use function is_file;
use function md5;
use function mkdir;
use function rmdir;
use function strlen;
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
 * Test for critical XObject mapping bug where images/assets get mapped to wrong object types.
 *
 * This regression test uses real PDF data from var/tmp/tmp_integrity_695b0f51e2f90/
 * to verify that after merging, XObjects still point to the correct Image objects
 * and not to ExtGState or other wrong types.
 */
final class XObjectMappingRegressionTest extends TestCase
{
    private FileIO $fileIO;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileIO  = new FileIO(self::getLogger());
        $this->tempDir = sys_get_temp_dir() . '/xobject_regression_' . uniqid();
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
     * CRITICAL TEST: Verifies XObject references point to correct object types after merge.
     *
     * This test uses actual PDF data that demonstrates the bug:
     * - Original page_534.pdf has 3 Image XObjects: I1 (512x512), I2 (320x320), I3 (400x400)
     * - After merging, these XObjects should STILL be Image objects with the same data
     * - BUG: They incorrectly point to ExtGState or contain wrong data
     *
     * This test will FAIL until the object mapping bug is fixed.
     */
    public function test_xobject_image_data_preserved_after_merge(): void
    {
        // Use the real test PDF files that demonstrate the bug
        $sourcePath = dirname(__DIR__, 5) . '/var/tmp/tmp_integrity_695b0f51e2f90/page_534.pdf';

        if (!file_exists($sourcePath)) {
            $this->markTestSkipped('Test PDF page_534.pdf not available. Run deep_pdf_integrity_analysis.php first.');
        }

        // Parse original to get expected XObject data
        $pdfParser   = new PDFParser(self::getLogger(), self::getCache());
        $pdfDocument = $pdfParser->parseDocumentFromFile($sourcePath, $this->fileIO);

        $originalPage = $pdfDocument->getPage(1);
        $this->assertNotNull($originalPage);

        $pdfObject = $originalPage->getValue();
        $this->assertInstanceOf(PDFDictionary::class, $pdfObject);

        // Extract original XObject data for comparison
        $originalResources = $pdfObject->getEntry('/Resources');

        if ($originalResources instanceof PDFReference) {
            $resourcesNode     = $pdfDocument->getObject($originalResources->getObjectNumber());
            $originalResources = $resourcesNode->getValue();
        }

        $originalXObjects = $originalResources->getEntry('/XObject');

        if ($originalXObjects instanceof PDFReference) {
            $xobjNode         = $pdfDocument->getObject($originalXObjects->getObjectNumber());
            $originalXObjects = $xobjNode->getValue();
        }

        // Collect expected XObject hashes and properties
        $expectedXObjects = [];

        foreach ($originalXObjects->getAllEntries() as $name => $allEntry) {
            $xobjNode = $pdfDocument->getObject($allEntry->getObjectNumber());
            $xobj     = $xobjNode->getValue();

            $this->assertInstanceOf(PDFStream::class, $xobj, "Original XObject {$name} should be a Stream");

            $dict                    = $xobj->getDictionary();
            $expectedXObjects[$name] = [
                'type'        => $dict->getEntry('/Type') ? $dict->getEntry('/Type')->getName() : null,
                'subtype'     => $dict->getEntry('/Subtype') ? $dict->getEntry('/Subtype')->getName() : null,
                'width'       => $dict->getEntry('/Width') ? $dict->getEntry('/Width')->getValue() : null,
                'height'      => $dict->getEntry('/Height') ? $dict->getEntry('/Height')->getValue() : null,
                'data_hash'   => md5($xobj->getDecodedData()),
                'data_length' => strlen($xobj->getDecodedData()),
            ];
        }

        $this->assertCount(3, $expectedXObjects, 'Original PDF should have 3 XObjects (I1, I2, I3)');
        $this->assertArrayHasKey('/I1', $expectedXObjects);
        $this->assertArrayHasKey('/I2', $expectedXObjects);
        $this->assertArrayHasKey('/I3', $expectedXObjects);

        // Merge the PDF
        $mergedPath = $this->tempDir . '/merged_regression.pdf';
        $pdfMerger  = new PDFMerger($this->fileIO, self::getLogger(), self::getEventDispatcher(), self::getCache());
        $pdfMerger->mergeIncremental([$sourcePath], $mergedPath);

        $this->assertFileExists($mergedPath);

        // Parse merged PDF and verify XObjects
        $mergedDoc  = $pdfParser->parseDocumentFromFile($mergedPath, $this->fileIO);
        $mergedPage = $mergedDoc->getPage(1);
        $this->assertNotNull($mergedPage);

        $mergedPageDict  = $mergedPage->getValue();
        $mergedResources = $mergedPageDict->getEntry('/Resources');

        if ($mergedResources instanceof PDFReference) {
            $resourcesNode   = $mergedDoc->getObject($mergedResources->getObjectNumber());
            $mergedResources = $resourcesNode->getValue();
        }

        $mergedXObjects = $mergedResources->getEntry('/XObject');

        if ($mergedXObjects instanceof PDFReference) {
            $xobjNode       = $mergedDoc->getObject($mergedXObjects->getObjectNumber());
            $mergedXObjects = $xobjNode->getValue();
        }

        $this->assertInstanceOf(PDFDictionary::class, $mergedXObjects, 'Merged PDF must have XObject dictionary');

        // CRITICAL VALIDATIONS: Check each XObject
        foreach ($expectedXObjects as $name => $expected) {
            $this->assertTrue(
                $mergedXObjects->hasEntry($name),
                "XObject {$name} must exist in merged PDF Resources",
            );

            $xobjRef = $mergedXObjects->getEntry($name);
            $this->assertInstanceOf(
                PDFReference::class,
                $xobjRef,
                "XObject {$name} must be a reference",
            );

            $xobjNode = $mergedDoc->getObject($xobjRef->getObjectNumber());
            $this->assertNotNull(
                $xobjNode,
                "XObject {$name} reference (object {$xobjRef->getObjectNumber()}) must resolve",
            );

            $xobj = $xobjNode->getValue();

            // CRITICAL: XObject must be a Stream, not a Dictionary
            $this->assertInstanceOf(
                PDFStream::class,
                $xobj,
                "XObject {$name} must be a Stream (contains image data), not " . $xobj::class .
                '. This indicates object number mapping bug where XObject points to wrong object type.',
            );

            $dict = $xobj->getDictionary();

            // Verify Type is XObject
            $type = $dict->getEntry('/Type');
            $this->assertInstanceOf(PDFName::class, $type, "XObject {$name} must have /Type");
            $this->assertEquals(
                'XObject',
                $type->getName(),
                "XObject {$name} /Type must be 'XObject', got '{$type->getName()}'. " .
                "If this is 'ExtGState' or 'Font', it means the object reference is pointing to the wrong object.",
            );

            // Verify Subtype is Image
            $subtype = $dict->getEntry('/Subtype');
            $this->assertInstanceOf(PDFName::class, $subtype, "XObject {$name} must have /Subtype");
            $this->assertEquals(
                $expected['subtype'],
                $subtype->getName(),
                "XObject {$name} /Subtype must match original",
            );

            // Verify dimensions match
            $width  = $dict->getEntry('/Width');
            $height = $dict->getEntry('/Height');

            $this->assertNotNull($width, "XObject {$name} must have /Width");
            $this->assertNotNull($height, "XObject {$name} must have /Height");

            $this->assertEquals(
                $expected['width'],
                $width->getValue(),
                "XObject {$name} width must match original ({$expected['width']})",
            );

            $this->assertEquals(
                $expected['height'],
                $height->getValue(),
                "XObject {$name} height must match original ({$expected['height']})",
            );

            // CRITICAL: Verify actual image data matches
            $actualData = $xobj->getDecodedData();
            $actualHash = md5($actualData);

            $this->assertEquals(
                $expected['data_length'],
                strlen($actualData),
                "XObject {$name} data length must match original ({$expected['data_length']} bytes). " .
                'Got ' . strlen($actualData) . ' bytes. This indicates the XObject is pointing to wrong data.',
            );

            $this->assertEquals(
                $expected['data_hash'],
                $actualHash,
                "XObject {$name} data hash must match original. " .
                'Data mismatch indicates XObject reference points to wrong object or data was corrupted.',
            );
        }
    }

    /**
     * Test that verifies the object mapping doesn't cause XObjects to point to ExtGState.
     *
     * This is a specific regression test for the bug where XObject references
     * incorrectly point to ExtGState objects instead of Image objects.
     */
    public function test_xobjects_dont_point_to_extgstate_objects(): void
    {
        $sourcePath = dirname(__DIR__, 5) . '/var/tmp/tmp_integrity_695b0f51e2f90/page_534.pdf';

        if (!file_exists($sourcePath)) {
            $this->markTestSkipped('Test PDF page_534.pdf not available');
        }

        // Merge the PDF
        $mergedPath = $this->tempDir . '/merged_extgstate_test.pdf';
        $pdfMerger  = new PDFMerger($this->fileIO, self::getLogger(), self::getEventDispatcher(), self::getCache());
        $pdfMerger->mergeIncremental([$sourcePath], $mergedPath);

        // Parse merged PDF
        $pdfParser   = new PDFParser(self::getLogger(), self::getCache());
        $pdfDocument = $pdfParser->parseDocumentFromFile($mergedPath, $this->fileIO);
        $mergedPage  = $pdfDocument->getPage(1);
        $pdfObject   = $mergedPage->getValue();

        $mergedResources = $pdfObject->getEntry('/Resources');

        if ($mergedResources instanceof PDFReference) {
            $resourcesNode   = $pdfDocument->getObject($mergedResources->getObjectNumber());
            $mergedResources = $resourcesNode->getValue();
        }

        $mergedXObjects = $mergedResources->getEntry('/XObject');

        if ($mergedXObjects instanceof PDFReference) {
            $xobjNode       = $pdfDocument->getObject($mergedXObjects->getObjectNumber());
            $mergedXObjects = $xobjNode->getValue();
        }

        // Check each XObject reference
        foreach ($mergedXObjects->getAllEntries() as $name => $allEntry) {
            $xobjNode = $pdfDocument->getObject($allEntry->getObjectNumber());
            $xobj     = $xobjNode->getValue();

            if ($xobj instanceof PDFDictionary && !($xobj instanceof PDFStream)) {
                // If it's a dictionary without stream, check its type
                $type = $xobj->getEntry('/Type');

                if ($type instanceof PDFName) {
                    $this->assertNotEquals(
                        'ExtGState',
                        $type->getName(),
                        "XObject {$name} incorrectly points to ExtGState object (object {$allEntry->getObjectNumber()}). " .
                        'This is the core bug - XObject references are mapped to wrong object numbers.',
                    );
                }
            }
        }
    }
}
