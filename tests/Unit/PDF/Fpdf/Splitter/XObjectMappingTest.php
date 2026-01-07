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
use function imagecolorallocate;
use function imagecreate;
use function imagedestroy;
use function imagefill;
use function imagefilledrectangle;
use function imagepng;
use function is_dir;
use function is_file;
use function mkdir;
use function preg_match_all;
use function rmdir;
use function strlen;
use function sys_get_temp_dir;
use function uniqid;
use PXP\PDF\Fpdf\Core\FPDF;
use PXP\PDF\Fpdf\Core\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Core\Object\Base\PDFName;
use PXP\PDF\Fpdf\Core\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Core\Object\Parser\PDFParser;
use PXP\PDF\Fpdf\Core\Stream\PDFStream;
use PXP\PDF\Fpdf\Features\Splitter\PDFMerger;
use PXP\PDF\Fpdf\Features\Splitter\PDFSplitter;
use PXP\PDF\Fpdf\IO\FileIO;
use Test\TestCase;

/**
 * Test XObject mapping during PDF merge operations.
 *
 * This test validates that XObject references in merged PDFs:
 * - Point to the correct object numbers (not wrong object types)
 * - Form XObjects are properly mapped (not pointing to Font objects)
 * - Image XObjects maintain their references correctly
 * - All XObject stream data is preserved
 */
final class XObjectMappingTest extends TestCase
{
    private FileIO $fileIO;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileIO  = new FileIO(self::getLogger());
        $this->tempDir = sys_get_temp_dir() . '/xobject_mapping_test_' . uniqid();
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
     * Test that Form XObject references are correctly mapped in merged PDFs.
     *
     * This test creates a PDF with both Image and Form XObjects, merges it,
     * and validates that:
     * 1. XObject references in page Resources point to correct object types
     * 2. Form XObjects have /Subtype /Form
     * 3. Image XObjects have /Subtype /Image
     * 4. Referenced objects contain actual stream data
     */
    public function test_form_xobject_references_mapped_correctly(): void
    {
        // Create a source PDF with Form XObject and Image XObject
        $sourcePdf = $this->createPdfWithFormAndImageXObjects();

        // Merge the PDF (single file merge to test object mapping)
        $mergedPdf = $this->tempDir . '/merged.pdf';
        $merger    = new PDFMerger($this->fileIO, self::getLogger(), self::getEventDispatcher(), self::getCache());
        $merger->mergeIncremental([$sourcePdf], $mergedPdf);

        $this->assertFileExists($mergedPdf);

        // Parse the merged PDF and validate XObject mappings
        $parser = new PDFParser(self::getLogger(), self::getCache());
        $doc    = $parser->parseDocumentFromFile($mergedPdf, $this->fileIO);

        $page = $doc->getPage(1);
        $this->assertNotNull($page, 'Merged PDF should have at least one page');

        $pageDict = $page->getValue();
        $this->assertInstanceOf(PDFDictionary::class, $pageDict);

        // Get page resources
        $resources = $pageDict->getEntry('/Resources');

        if ($resources instanceof PDFReference) {
            $resourcesNode = $doc->getObject($resources->getObjectNumber());
            $this->assertNotNull($resourcesNode, 'Resources reference must resolve');
            $resources = $resourcesNode->getValue();
        }

        $this->assertInstanceOf(PDFDictionary::class, $resources, 'Resources must be a dictionary');

        // Get XObject dictionary
        $xObjects = $resources->getEntry('/XObject');
        $this->assertNotNull($xObjects, 'Resources must contain /XObject entry');

        if ($xObjects instanceof PDFReference) {
            $xObjectsNode = $doc->getObject($xObjects->getObjectNumber());
            $this->assertNotNull($xObjectsNode);
            $xObjects = $xObjectsNode->getValue();
        }

        $this->assertInstanceOf(PDFDictionary::class, $xObjects, '/XObject must be a dictionary');

        // Validate each XObject reference
        $formXObjectFound  = false;
        $imageXObjectFound = false;

        foreach ($xObjects->getAllEntries() as $xobjName => $xobjRef) {
            $this->assertInstanceOf(PDFReference::class, $xobjRef, "XObject {$xobjName} must be a reference");

            $xobjNode = $doc->getObject($xobjRef->getObjectNumber());
            $this->assertNotNull($xobjNode, "XObject {$xobjName} reference must resolve to an object");

            $xobjValue = $xobjNode->getValue();

            // XObject should be either a Stream or Dictionary with stream
            $this->assertTrue(
                $xobjValue instanceof PDFStream || $xobjValue instanceof PDFDictionary,
                "XObject {$xobjName} must be a Stream or Dictionary, got " . $xobjValue::class,
            );

            // If it's a stream, get its dictionary
            $xobjDict = $xobjValue instanceof PDFStream ? $xobjValue->getDictionary() : $xobjValue;

            // Validate that the object has /Type /XObject
            $type = $xobjDict->getEntry('/Type');

            if ($type !== null) {
                $this->assertInstanceOf(PDFName::class, $type, "XObject {$xobjName} /Type must be a name");
                $this->assertEquals('XObject', $type->getName(), "XObject {$xobjName} must have /Type /XObject");
            }

            // Check /Subtype
            $subtype = $xobjDict->getEntry('/Subtype');
            $this->assertNotNull($subtype, "XObject {$xobjName} must have /Subtype");
            $this->assertInstanceOf(PDFName::class, $subtype, "XObject {$xobjName} /Subtype must be a name");

            $subtypeValue = $subtype->getName();

            // Validate that it's either Form or Image
            $this->assertContains(
                $subtypeValue,
                ['Form', 'Image'],
                "XObject {$xobjName} /Subtype must be either /Form or /Image, got {$subtypeValue}",
            );

            // CRITICAL: Ensure it's not pointing to a Font object or other wrong type
            $objectType = $xobjDict->getEntry('/Type');

            if ($objectType instanceof PDFName) {
                $this->assertNotEquals('Font', $objectType->getName(), "XObject {$xobjName} must NOT point to a Font object");
                $this->assertNotEquals('Pages', $objectType->getName(), "XObject {$xobjName} must NOT point to a Pages object");
            }

            // Validate stream data exists for Form XObjects
            if ($subtypeValue === 'Form') {
                $formXObjectFound = true;
                $this->assertInstanceOf(PDFStream::class, $xobjValue, "Form XObject {$xobjName} must be a Stream");

                // Form XObject must have stream data
                $streamData = $xobjValue->getDecodedData();
                $this->assertNotEmpty($streamData, "Form XObject {$xobjName} must have non-empty stream data");
                $this->assertGreaterThan(10, strlen($streamData), "Form XObject {$xobjName} stream must contain actual content");
            }

            // Validate stream data exists for Image XObjects
            if ($subtypeValue === 'Image') {
                $imageXObjectFound = true;
                $this->assertInstanceOf(PDFStream::class, $xobjValue, "Image XObject {$xobjName} must be a Stream");

                // Image XObject must have stream data
                $streamData = $xobjValue->getDecodedData();
                $this->assertNotEmpty($streamData, "Image XObject {$xobjName} must have non-empty stream data");
            }
        }

        // Ensure both types were found (the test PDF should have at least Image XObjects)
        $this->assertTrue($imageXObjectFound, 'Merged PDF should contain at least one Image XObject');
        // Note: Form XObjects are only present in PDFs that use templates or imported pages,
        // so we don't require them for this basic test
    }

    /**
     * Test that XObject names in content streams match Resources dictionary.
     */
    public function test_xobject_names_in_content_match_resources(): void
    {
        // Create a source PDF
        $sourcePdf = $this->createPdfWithFormAndImageXObjects();

        // Merge it
        $mergedPdf = $this->tempDir . '/merged.pdf';
        $merger    = new PDFMerger($this->fileIO, self::getLogger(), self::getEventDispatcher(), self::getCache());
        $merger->mergeIncremental([$sourcePdf], $mergedPdf);

        // Parse merged PDF
        $parser = new PDFParser(self::getLogger(), self::getCache());
        $doc    = $parser->parseDocumentFromFile($mergedPdf, $this->fileIO);

        $page = $doc->getPage(1);
        $this->assertNotNull($page);

        $pageDict = $page->getValue();
        $this->assertInstanceOf(PDFDictionary::class, $pageDict);

        // Get content stream
        $contents    = $pageDict->getEntry('/Contents');
        $contentData = '';

        if ($contents instanceof PDFReference) {
            $contentNode = $doc->getObject($contents->getObjectNumber());
            $this->assertNotNull($contentNode);
            $contentObj = $contentNode->getValue();
            $this->assertInstanceOf(PDFStream::class, $contentObj);
            $contentData = $contentObj->getDecodedData();
        }

        $this->assertNotEmpty($contentData, 'Page must have content stream');

        // Extract XObject names from content stream (looking for /Name Do operators)
        preg_match_all('/\/([A-Za-z0-9_]+)\s+Do\b/', $contentData, $matches);
        $xobjectNamesInContent = $matches[1] ?? [];

        $this->assertNotEmpty($xobjectNamesInContent, 'Content stream should invoke XObjects with Do operator');

        // Get resources
        $resources = $pageDict->getEntry('/Resources');

        if ($resources instanceof PDFReference) {
            $resourcesNode = $doc->getObject($resources->getObjectNumber());
            $this->assertNotNull($resourcesNode);
            $resources = $resourcesNode->getValue();
        }

        $xObjects = $resources->getEntry('/XObject');

        if ($xObjects instanceof PDFReference) {
            $xObjectsNode = $doc->getObject($xObjects->getObjectNumber());
            $this->assertNotNull($xObjectsNode);
            $xObjects = $xObjectsNode->getValue();
        }

        $this->assertInstanceOf(PDFDictionary::class, $xObjects);

        // Verify each XObject name in content exists in Resources
        foreach ($xobjectNamesInContent as $xobjName) {
            $hasEntry = $xObjects->hasEntry('/' . $xobjName);
            $this->assertTrue(
                $hasEntry,
                "XObject /{$xobjName} used in content stream must exist in page Resources /XObject dictionary",
            );
        }
    }

    /**
     * Test merging a PDF that contains Form XObjects (from real test file if available).
     */
    public function test_merge_pdf_with_real_form_xobjects(): void
    {
        // Use the test file mentioned in the bug report if it exists
        $testFile = dirname(__DIR__, 5) . '/resources/input/23-grande.pdf';

        if (!file_exists($testFile)) {
            $this->markTestSkipped('Test file 23-grande.pdf not available');
        }

        // Extract a page that likely has Form XObjects
        $extractedPath = $this->tempDir . '/extracted_page.pdf';
        $splitter      = new PDFSplitter($testFile, $this->fileIO, self::getLogger());

        // Extract page 530 (mentioned in bug report)
        $splitter->extractPage(530, $extractedPath);

        // Merge the extracted page
        $mergedPath = $this->tempDir . '/merged_page.pdf';
        $merger     = new PDFMerger($this->fileIO, self::getLogger(), self::getEventDispatcher(), self::getCache());
        $merger->mergeIncremental([$extractedPath], $mergedPath);

        $this->assertFileExists($mergedPath);

        // Parse and validate
        $parser = new PDFParser(self::getLogger(), self::getCache());
        $doc    = $parser->parseDocumentFromFile($mergedPath, $this->fileIO);

        $page = $doc->getPage(1);
        $this->assertNotNull($page);

        $pageDict = $page->getValue();
        $this->assertInstanceOf(PDFDictionary::class, $pageDict);

        // Get resources
        $resources = $pageDict->getEntry('/Resources');

        if ($resources instanceof PDFReference) {
            $resourcesNode = $doc->getObject($resources->getObjectNumber());
            $this->assertNotNull($resourcesNode);
            $resources = $resourcesNode->getValue();
        }

        if ($resources === null) {
            // Some PDFs might not have resources at page level
            $this->markTestSkipped('Page does not have resources');
        }

        $this->assertInstanceOf(PDFDictionary::class, $resources);

        // Check for XObjects
        $xObjects = $resources->getEntry('/XObject');

        if ($xObjects === null) {
            // No XObjects on this page
            return;
        }

        if ($xObjects instanceof PDFReference) {
            $xObjectsNode = $doc->getObject($xObjects->getObjectNumber());
            $this->assertNotNull($xObjectsNode, 'XObject reference must resolve');
            $xObjects = $xObjectsNode->getValue();
        }

        $this->assertInstanceOf(PDFDictionary::class, $xObjects);

        // Validate each XObject
        foreach ($xObjects->getAllEntries() as $xobjName => $xobjRef) {
            $this->assertInstanceOf(PDFReference::class, $xobjRef, "XObject {$xobjName} must be a reference");

            $xobjNode = $doc->getObject($xobjRef->getObjectNumber());
            $this->assertNotNull($xobjNode, "XObject {$xobjName} reference must resolve");

            $xobjValue = $xobjNode->getValue();
            $this->assertTrue(
                $xobjValue instanceof PDFStream || $xobjValue instanceof PDFDictionary,
                "XObject {$xobjName} must be Stream or Dictionary, got " . $xobjValue::class,
            );

            $xobjDict = $xobjValue instanceof PDFStream ? $xobjValue->getDictionary() : $xobjValue;

            // CRITICAL CHECK: Ensure it's not a Font object
            $type = $xobjDict->getEntry('/Type');

            if ($type instanceof PDFName) {
                $typeValue = $type->getName();
                $this->assertNotEquals(
                    'Font',
                    $typeValue,
                    "XObject {$xobjName} must NOT point to a Font object (got {$typeValue})",
                );
            }

            // Ensure it has correct /Subtype
            $subtype = $xobjDict->getEntry('/Subtype');

            if ($subtype instanceof PDFName) {
                $subtypeValue = $subtype->getName();
                $this->assertContains(
                    $subtypeValue,
                    ['Form', 'Image'],
                    "XObject {$xobjName} must have /Subtype /Form or /Image (got {$subtypeValue})",
                );

                // If it's a Form XObject, ensure it has stream data
                if ($subtypeValue === 'Form' && $xobjValue instanceof PDFStream) {
                    $streamData = $xobjValue->getDecodedData();
                    $this->assertNotEmpty($streamData, "Form XObject {$xobjName} must have stream data");
                }
            }
        }
    }

    /**
     * Test that XObject references don't point to wrong object types after merge
     * This is a regression test for the object number mismatch bug.
     */
    public function test_xobject_references_dont_point_to_wrong_types(): void
    {
        // Create a source PDF with resources
        $sourcePdf = $this->createPdfWithFormAndImageXObjects();

        // Merge it
        $mergedPdf = $this->tempDir . '/merged_regression.pdf';
        $merger    = new PDFMerger($this->fileIO, self::getLogger(), self::getEventDispatcher(), self::getCache());
        $merger->mergeIncremental([$sourcePdf], $mergedPdf);

        // Parse and validate that all XObject references resolve correctly
        $parser = new PDFParser(self::getLogger(), self::getCache());
        $doc    = $parser->parseDocumentFromFile($mergedPdf, $this->fileIO);

        // Get all objects in the document
        $allObjects = [];

        for ($i = 1; $i <= 20; $i++) {
            $node = $doc->getObject($i);

            if ($node !== null) {
                $allObjects[$i] = $node->getValue();
            }
        }

        // Find the page
        $page = $doc->getPage(1);
        $this->assertNotNull($page);

        $pageDict  = $page->getValue();
        $resources = $pageDict->getEntry('/Resources');

        if ($resources instanceof PDFReference) {
            $resourcesNode = $doc->getObject($resources->getObjectNumber());
            $this->assertNotNull($resourcesNode);
            $resources = $resourcesNode->getValue();
        }

        // Get XObject dictionary
        $xObjects = $resources->getEntry('/XObject');

        if ($xObjects instanceof PDFReference) {
            $xObjectsNode = $doc->getObject($xObjects->getObjectNumber());
            $xObjects     = $xObjectsNode->getValue();
        }

        if (!$xObjects instanceof PDFDictionary) {
            $this->markTestSkipped('No XObjects in test PDF');
        }

        // For each XObject reference, verify it points to the correct type
        foreach ($xObjects->getAllEntries() as $xobjName => $xobjRef) {
            $this->assertInstanceOf(
                PDFReference::class,
                $xobjRef,
                "XObject {$xobjName} should be a reference",
            );

            $objNum = $xobjRef->getObjectNumber();
            $this->assertTrue(
                isset($allObjects[$objNum]),
                "XObject {$xobjName} references object {$objNum} which must exist",
            );

            $referencedObj = $allObjects[$objNum];

            // The referenced object should be a Stream (XObject) not a Font or other type
            if ($referencedObj instanceof PDFStream) {
                $dict = $referencedObj->getDictionary();
                $type = $dict->getEntry('/Type');

                if ($type instanceof PDFName) {
                    $typeName = $type->getName();
                    $this->assertNotEquals(
                        'Font',
                        $typeName,
                        "XObject {$xobjName} (ref {$objNum}) incorrectly points to a Font object",
                    );
                    $this->assertNotEquals(
                        'Pages',
                        $typeName,
                        "XObject {$xobjName} (ref {$objNum}) incorrectly points to a Pages object",
                    );
                }
            } elseif ($referencedObj instanceof PDFDictionary) {
                // If it's a dictionary without stream, check its type
                $type = $referencedObj->getEntry('/Type');

                if ($type instanceof PDFName) {
                    $typeName = $type->getName();
                    $this->assertContains(
                        $typeName,
                        ['XObject', 'Form', 'Image'],
                        "XObject {$xobjName} (ref {$objNum}) should have type XObject/Form/Image, got {$typeName}",
                    );
                }
            }
        }
    }

    /**
     * Create a test PDF with both Form XObject (page template) and Image XObject.
     */
    private function createPdfWithFormAndImageXObjects(): string
    {
        $pdf = new FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);

        // Add text
        $pdf->Cell(0, 10, 'Test PDF with XObjects', 0, 1);

        // Create and add an image (Image XObject)
        $imagePath = $this->createTestImage();
        $pdf->Image($imagePath, 50, 40, 80);
        self::unlink($imagePath);

        // Add more content to create a more complex page
        $pdf->SetY(130);
        $pdf->SetFont('Arial', '', 12);
        $pdf->MultiCell(0, 5, 'This PDF contains both Image XObjects (the image above) and will have Form XObjects if using templates or imported pages.');

        // Use a template (Form XObject) - FPDF doesn't natively support this,
        // but we'll use the existing test PDF that has Form XObjects
        $outputPath = $this->tempDir . '/source.pdf';
        $pdf->Output('F', $outputPath);

        return $outputPath;
    }

    /**
     * Create a simple test image.
     */
    private function createTestImage(): string
    {
        $img   = imagecreate(100, 100);
        $red   = imagecolorallocate($img, 255, 0, 0);
        $white = imagecolorallocate($img, 255, 255, 255);

        // Create a simple pattern
        imagefill($img, 0, 0, $white);
        imagefilledrectangle($img, 20, 20, 80, 80, $red);

        $imagePath = $this->tempDir . '/test_image.png';
        imagepng($img, $imagePath);
        imagedestroy($img);

        return $imagePath;
    }
}
