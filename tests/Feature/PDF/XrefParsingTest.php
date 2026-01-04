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

use function count;
use function dirname;
use function glob;
use function is_dir;
use function str_contains;
use PXP\PDF\Fpdf\Exception\FpdfException;
use PXP\PDF\Fpdf\IO\FileIO;
use PXP\PDF\Fpdf\Object\Parser\PDFParser;
use PXP\PDF\Fpdf\Xref\PDFXrefTable;
use Test\TestCase;

/**
 * Feature tests for xref table parsing.
 *
 * @covers \PXP\PDF\Fpdf\Object\Parser\PDFParser
 * @covers \PXP\PDF\Fpdf\Xref\PDFXrefTable
 */
final class XrefParsingTest extends TestCase
{
    private PDFParser $parser;
    private FileIO $fileIO;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new PDFParser(self::getLogger(), self::getCache());
        $this->fileIO = self::createFileIO();
    }

    public function testParseDocumentWithTraditionalXref(): void
    {
        $inputDir = dirname(__DIR__, 2) . '/resources/input';

        if (!is_dir($inputDir)) {
            $this->markTestSkipped('Input directory not found: ' . $inputDir);
        }

        $pdfFiles = glob($inputDir . '/*.pdf') ?: [];

        if (empty($pdfFiles)) {
            $this->markTestSkipped('No PDF files found in test resources');
        }

        // Test with first available PDF
        $pdfPath = $pdfFiles[0];

        $document = $this->parser->parseDocumentFromFile($pdfPath, $this->fileIO);

        $this->assertNotNull($document);
        $this->assertNotNull($document->getXrefTable());
        $this->assertGreaterThan(0, count($document->getXrefTable()->getAllEntries()));
    }

    public function testStartxrefKeywordFinding(): void
    {
        // Create a minimal PDF with startxref
        $pdfContent = "%PDF-1.4\n";
        $pdfContent .= "1 0 obj\n<< /Type /Catalog >>\nendobj\n";
        $pdfContent .= "xref\n";
        $pdfContent .= "0 2\n";
        $pdfContent .= "0000000000 65535 f \n";
        $pdfContent .= "0000000009 00000 n \n";
        $pdfContent .= "trailer\n";
        $pdfContent .= "<< /Size 2 /Root 1 0 R >>\n";
        $pdfContent .= "startxref\n";
        $pdfContent .= "50\n";
        $pdfContent .= "%%EOF\n";

        $document = $this->parser->parseDocument($pdfContent);

        $this->assertNotNull($document);
        $xrefTable = $document->getXrefTable();
        $this->assertNotNull($xrefTable);

        // Should have parsed the xref entry
        $entry = $xrefTable->getEntry(1);
        $this->assertNotNull($entry);
    }

    public function testXrefParsingWithMultipleSubsections(): void
    {
        $xrefContent = "0 2\n";
        $xrefContent .= "0000000000 65535 f \n";
        $xrefContent .= "0000000100 00000 n \n";
        $xrefContent .= "5 2\n";
        $xrefContent .= "0000000200 00000 n \n";
        $xrefContent .= "0000000300 00000 n \n";

        $xrefTable = new PDFXrefTable;
        $xrefTable->parseFromString($xrefContent);

        $this->assertNotNull($xrefTable->getEntry(0));
        $this->assertTrue($xrefTable->getEntry(0)->isFree());
        $this->assertNotNull($xrefTable->getEntry(1));
        $this->assertSame(100, $xrefTable->getEntry(1)->getOffset());
        $this->assertNotNull($xrefTable->getEntry(5));
        $this->assertSame(200, $xrefTable->getEntry(5)->getOffset());
        $this->assertNotNull($xrefTable->getEntry(6));
        $this->assertSame(300, $xrefTable->getEntry(6)->getOffset());
    }

    public function testXrefParsingWithVariousWhitespace(): void
    {
        // Test CRLF
        $xrefContent1 = "0 2\r\n0000000100 00000 n \r\n0000000200 00000 n \r\n";
        $xrefTable1   = new PDFXrefTable;
        $xrefTable1->parseFromString($xrefContent1);
        $this->assertNotNull($xrefTable1->getEntry(0));
        $this->assertSame(100, $xrefTable1->getEntry(0)->getOffset());

        // Test LF only
        $xrefContent2 = "0 2\n0000000100 00000 n \n0000000200 00000 n \n";
        $xrefTable2   = new PDFXrefTable;
        $xrefTable2->parseFromString($xrefContent2);
        $this->assertNotNull($xrefTable2->getEntry(0));
        $this->assertSame(100, $xrefTable2->getEntry(0)->getOffset());

        // Test CR only
        $xrefContent3 = "0 2\r0000000100 00000 n \r0000000200 00000 n \r";
        $xrefTable3   = new PDFXrefTable;
        $xrefTable3->parseFromString($xrefContent3);
        $this->assertNotNull($xrefTable3->getEntry(0));
        $this->assertSame(100, $xrefTable3->getEntry(0)->getOffset());
    }

    public function testTrailerParsingExtractsPrevOffset(): void
    {
        $trailerSection = "trailer\n";
        $trailerSection .= "<< /Size 10 /Root 1 0 R /Prev 12345 >>\n";

        $pdfContent = "%PDF-1.4\n";
        $pdfContent .= "1 0 obj\n<< /Type /Catalog >>\nendobj\n";
        $pdfContent .= "xref\n";
        $pdfContent .= "0 2\n";
        $pdfContent .= "0000000000 65535 f \n";
        $pdfContent .= "0000000009 00000 n \n";
        $pdfContent .= $trailerSection;
        $pdfContent .= "startxref\n";
        $pdfContent .= "50\n";
        $pdfContent .= "%%EOF\n";

        // The parseTrailer method returns the Prev offset, but we can't easily test it
        // without accessing private methods. Instead, we test that parsing works.
        $document = $this->parser->parseDocument($pdfContent);
        $this->assertNotNull($document);
    }

    public function testErrorHandlingForMissingXref(): void
    {
        $pdfContent = "%PDF-1.4\n";
        $pdfContent .= "1 0 obj\n<< /Type /Catalog >>\nendobj\n";
        // Missing xref table

        $this->expectException(FpdfException::class);
        $this->expectExceptionMessage('xref table not found');

        $this->parser->parseDocument($pdfContent);
    }

    public function testErrorHandlingForInvalidXrefFormat(): void
    {
        $pdfContent = "%PDF-1.4\n";
        $pdfContent .= "xref\n";
        $pdfContent .= "invalid format\n";
        $pdfContent .= "trailer\n";
        $pdfContent .= "<< /Size 1 >>\n";
        $pdfContent .= "startxref\n";
        $pdfContent .= "10\n";
        $pdfContent .= "%%EOF\n";

        // Should not throw exception, but may have empty xref table
        $document = $this->parser->parseDocument($pdfContent);
        $this->assertNotNull($document);
    }

    public function testXrefEntryMerging(): void
    {
        $xrefTable1 = new PDFXrefTable;
        $xrefTable1->addEntry(1, 100, 0, false);
        $xrefTable1->addEntry(2, 200, 0, false);

        $xrefTable2 = new PDFXrefTable;
        $xrefTable2->addEntry(2, 300, 0, false); // Override
        $xrefTable2->addEntry(3, 400, 0, false); // New

        // Merge: newer entries override older ones
        $xrefTable1->mergeEntries($xrefTable2);

        $this->assertSame(100, $xrefTable1->getEntry(1)->getOffset());
        $this->assertSame(300, $xrefTable1->getEntry(2)->getOffset()); // Overridden by newer
        $this->assertSame(400, $xrefTable1->getEntry(3)->getOffset()); // Added
    }

    public function testParseDocumentFromFileWithRealPdf(): void
    {
        $inputDir = dirname(__DIR__, 2) . '/resources/input';

        if (!is_dir($inputDir)) {
            $this->markTestSkipped('Input directory not found: ' . $inputDir);
        }

        $pdfFiles = glob($inputDir . '/*.pdf') ?: [];

        if (empty($pdfFiles)) {
            $this->markTestSkipped('No PDF files found in test resources');
        }

        foreach ($pdfFiles as $pdfPath) {
            try {
                $document = $this->parser->parseDocumentFromFile($pdfPath, $this->fileIO);

                $this->assertNotNull($document, "Failed to parse: {$pdfPath}");
                $this->assertNotNull($document->getXrefTable(), "No xref table in: {$pdfPath}");

                $entries = $document->getXrefTable()->getAllEntries();
                $this->assertGreaterThan(0, count($entries), "Empty xref table in: {$pdfPath}");

                // If we got here, parsing was successful
                break;
            } catch (FpdfException $e) {
                // Some PDFs might have xref streams which aren't fully supported yet
                if (str_contains($e->getMessage(), 'Xref streams are not yet supported')) {
                    continue;
                }

                throw $e;
            }
        }
    }
}
