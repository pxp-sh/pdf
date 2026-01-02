<?php

declare(strict_types=1);

/**
 * Copyright (c) 2025-2026 PXP
 */

namespace Test\Feature\PDF;

use Test\TestCase;
use PXP\PDF\Fpdf\FPDF;
use PXP\PDF\Fpdf\Stream\PDFStream;
use PXP\PDF\Fpdf\Tree\PDFDocument;
use PXP\PDF\Fpdf\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Object\Base\PDFReference;

final class MergeResourcesTest extends TestCase
{
    public function test_merged_page_resources_are_copied(): void
    {
        if (!method_exists(FPDF::class, 'mergePdf')) {
            $this->markTestSkipped('mergePdf method not yet implemented');
        }

        $inputDir = dirname(__DIR__, 2) . '/resources/input';
        $srcPdf = $inputDir . '/2402.04367v1.pdf';
        if (!is_file($srcPdf)) {
            $this->markTestSkipped('Test PDF not available: ' . $srcPdf);
        }

        $tmpDir = self::getRootDir() . '/tmp_merge_' . uniqid();
        mkdir($tmpDir, 0777, true);
        $out = $tmpDir . '/merged.pdf';

        FPDF::mergePdf([$srcPdf], $out, self::getLogger(), self::getCache(), self::getEventDispatcher());
        $this->assertFileExists($out);

        // Parse merged PDF and inspect first page resources using full parser
        $parser = new \PXP\PDF\Fpdf\Object\Parser\PDFParser(self::getLogger(), self::getCache());
        $doc = $parser->parseDocumentFromFile($out, new \PXP\PDF\Fpdf\IO\FileIO(self::getLogger()));
        $page = $doc->getPage(1);
        $this->assertNotNull($page, 'Merged PDF should contain at least one page (parsed)');

        $pageDict = $page->getValue();
        $this->assertInstanceOf(PDFDictionary::class, $pageDict);

        $resources = $pageDict->getEntry('/Resources');
        if ($resources instanceof PDFReference) {
            $resourcesNode = $doc->getObject($resources->getObjectNumber());
            $this->assertNotNull($resourcesNode, 'Resources reference must resolve');
            $resources = $resourcesNode->getValue();
        }

        $this->assertInstanceOf(PDFDictionary::class, $resources, 'Page resources should be a dictionary');

        // Extract content stream data
        $contents = $pageDict->getEntry('/Contents');
        $data = '';
        if ($contents instanceof PDFReference) {
            $node = $doc->getObject($contents->getObjectNumber());
            $this->assertNotNull($node);
            $obj = $node->getValue();
            $this->assertInstanceOf(PDFStream::class, $obj);
            $data .= $obj->getDecodedData();
        } elseif ($contents instanceof \PXP\PDF\Fpdf\Object\Base\PDFArray) {
            foreach ($contents->getAll() as $item) {
                if ($item instanceof PDFReference) {
                    $node = $doc->getObject($item->getObjectNumber());
                    $this->assertNotNull($node);
                    $obj = $node->getValue();
                    if ($obj instanceof PDFStream) {
                        $data .= $obj->getDecodedData();
                    }
                }
            }
        }

        // Find resource names used in the content (Tf for fonts, Do for XObjects).
        // Use stricter patterns to avoid false matches (e.g. marked content tags like /Artifact ... BDC)
        $matches = [];
        preg_match_all('/\/([A-Za-z0-9_]+)(?:\s+\d+(?:\.\d+)?){1,3}\s+Tf/', $data, $mTf);
        preg_match_all('/\/([A-Za-z0-9_]+)\s+Do\b/', $data, $mDo);

        $names = array_merge($mTf[1] ?? [], $mDo[1] ?? []);
        $this->assertNotEmpty($names, 'No resource names found in page content');

        $usedResources = array_unique($names);

        $fontTable = $resources->getEntry('/Font');
        $xobjTable = $resources->getEntry('/XObject');

        foreach ($usedResources as $rname) {
            $found = false;
            if ($fontTable instanceof PDFDictionary && $fontTable->hasEntry('/' . $rname)) {
                $found = true;
            }
            if ($xobjTable instanceof PDFDictionary && $xobjTable->hasEntry('/' . $rname)) {
                $found = true;
            }

            $this->assertTrue($found, sprintf('Resource "%s" used in content must be present in page /Resources', $rname));
        }

        // Clean up
        self::unlink($out);
    }

    public function test_merge_file_with_previously_zero_pages_yields_pages(): void
    {
        if (!method_exists(FPDF::class, 'mergePdf')) {
            $this->markTestSkipped('mergePdf method not yet implemented');
        }

        $inputDir = dirname(__DIR__, 2) . '/resources/input';
        $srcPdf = $inputDir . '/3722041.3723104.pdf';
        if (!is_file($srcPdf)) {
            $this->markTestSkipped('Test PDF not available: ' . $srcPdf);
        }

        $tmpDir = self::getRootDir() . '/tmp_merge_' . uniqid();
        mkdir($tmpDir, 0777, true);
        $out = $tmpDir . '/merged.pdf';

        FPDF::mergePdf([$srcPdf], $out, self::getLogger(), self::getCache(), self::getEventDispatcher());
        $this->assertFileExists($out);

        $expected = self::getPdfPageCount($srcPdf);
        $actual = self::getPdfPageCount($out);

        $this->assertEquals($expected, $actual, 'Merged PDF should have same page count as source');

        self::unlink($out);
    }
}
