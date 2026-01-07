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
namespace Test\Unit\PDF\Fpdf\Xref;

use PXP\PDF\Fpdf\Core\Xref\PDFXrefTable;
use PXP\PDF\Fpdf\Core\Xref\XrefStreamParser;
use PXP\PDF\Fpdf\Exceptions\Exception\FpdfException;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Xref\XrefStreamParser
 */
final class XrefStreamParserTest extends TestCase
{
    private XrefStreamParser $xrefStreamParser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->xrefStreamParser = new XrefStreamParser;
    }

    public function testParseStreamWithType1Entries(): void
    {
        $pdfXrefTable = new PDFXrefTable;

        // Simple stream with type 1 entries (in-use, uncompressed)
        // W = [1, 2, 2] means: 1 byte for type, 2 bytes for field1 (offset), 2 bytes for field2 (generation)
        // Stream data: type=1, offset=100 (0x0064), generation=0 (0x0000), type=1, offset=200 (0x00C8), generation=0
        $streamData = "\x01\x00\x64\x00\x00\x01\x00\xC8\x00\x00";

        $streamDict = [
            ['/', '/Type'],
            ['/', '/XRef'],
            ['/', '/W'],
            ['[', [
                ['numeric', '1'],
                ['numeric', '2'],
                ['numeric', '2'],
            ]],
            ['/', '/Index'],
            ['[', [
                ['numeric', '0'],
                ['numeric', '2'],
            ]],
        ];

        $prevOffset = $this->xrefStreamParser->parseStream($streamData, $streamDict, $pdfXrefTable);

        $this->assertNull($prevOffset);
        $entry0 = $pdfXrefTable->getEntry(0);
        $this->assertNotNull($entry0);
        $this->assertSame(100, $entry0->getOffset());
        $this->assertSame(0, $entry0->getGeneration());
        $this->assertFalse($entry0->isFree());

        $entry1 = $pdfXrefTable->getEntry(1);
        $this->assertNotNull($entry1);
        $this->assertSame(200, $entry1->getOffset());
    }

    public function testParseStreamWithFreeEntries(): void
    {
        $pdfXrefTable = new PDFXrefTable;

        // Stream with type 0 (free) and type 1 (in-use) entries
        $streamData = "\x00\x00\x00\x00\x00\x01\x00\x64\x00\x00";

        $streamDict = [
            ['/', '/Type'],
            ['/', '/XRef'],
            ['/', '/W'],
            ['[', [
                ['numeric', '1'],
                ['numeric', '2'],
                ['numeric', '2'],
            ]],
            ['/', '/Index'],
            ['[', [
                ['numeric', '0'],
                ['numeric', '2'],
            ]],
        ];

        $this->xrefStreamParser->parseStream($streamData, $streamDict, $pdfXrefTable);

        $pdfXrefTable->getEntry(0);
        // Type 0 entries are free but we skip them in current implementation
        // This test verifies the parser doesn't crash on free entries

        $entry1 = $pdfXrefTable->getEntry(1);
        $this->assertNotNull($entry1);
        $this->assertSame(100, $entry1->getOffset());
    }

    public function testParseStreamWithMultipleSubsections(): void
    {
        $pdfXrefTable = new PDFXrefTable;

        // Stream with Index [0, 2, 5, 2] meaning: objects 0-1, then objects 5-6
        $streamData = "\x01\x00\x64\x00\x00\x01\x00\xC8\x00\x00\x01\x01\x2C\x00\x00\x01\x01\x90\x00\x00";

        $streamDict = [
            ['/', '/Type'],
            ['/', '/XRef'],
            ['/', '/W'],
            ['[', [
                ['numeric', '1'],
                ['numeric', '2'],
                ['numeric', '2'],
            ]],
            ['/', '/Index'],
            ['[', [
                ['numeric', '0'],
                ['numeric', '2'],
                ['numeric', '5'],
                ['numeric', '2'],
            ]],
        ];

        $this->xrefStreamParser->parseStream($streamData, $streamDict, $pdfXrefTable);

        $this->assertNotNull($pdfXrefTable->getEntry(0));
        $this->assertNotNull($pdfXrefTable->getEntry(1));
        $this->assertNotNull($pdfXrefTable->getEntry(5));
        $this->assertNotNull($pdfXrefTable->getEntry(6));
    }

    public function testParseStreamWithPrevOffset(): void
    {
        $pdfXrefTable = new PDFXrefTable;

        $streamData = "\x01\x00\x64\x00\x00";

        $streamDict = [
            ['/', '/Type'],
            ['/', '/XRef'],
            ['/', '/W'],
            ['[', [
                ['numeric', '1'],
                ['numeric', '2'],
                ['numeric', '2'],
            ]],
            ['/', '/Prev'],
            ['numeric', '12345'],
        ];

        $prevOffset = $this->xrefStreamParser->parseStream($streamData, $streamDict, $pdfXrefTable);

        $this->assertSame(12345, $prevOffset);
    }

    public function testParseStreamThrowsExceptionForInvalidType(): void
    {
        $pdfXrefTable = new PDFXrefTable;

        $streamData = "\x01\x00\x64\x00\x00";

        $streamDict = [
            ['/', '/Type'],
            ['/', '/InvalidType'],
        ];

        $this->expectException(FpdfException::class);
        $this->expectExceptionMessage('Invalid xref stream: Type must be /XRef');

        $this->xrefStreamParser->parseStream($streamData, $streamDict, $pdfXrefTable);
    }

    public function testParseStreamThrowsExceptionForInvalidW(): void
    {
        $pdfXrefTable = new PDFXrefTable;

        $streamData = "\x01\x00\x64\x00\x00";

        $streamDict = [
            ['/', '/Type'],
            ['/', '/XRef'],
            ['/', '/W'],
            ['[', [
                ['numeric', '1'],
                ['numeric', '2'],
            ]], // Only 2 values, need 3
        ];

        $this->expectException(FpdfException::class);
        $this->expectExceptionMessage('Invalid xref stream: /W must be array of 3 integers');

        $this->xrefStreamParser->parseStream($streamData, $streamDict, $pdfXrefTable);
    }

    public function testParseStreamWithPngPredictor(): void
    {
        // This test would require actual PNG predictor encoded data
        // For now, we'll test that the method doesn't crash with predictor settings
        $pdfXrefTable = new PDFXrefTable;

        $streamData = "\x01\x00\x64\x00\x00";

        $streamDict = [
            ['/', '/Type'],
            ['/', '/XRef'],
            ['/', '/W'],
            ['[', [
                ['numeric', '1'],
                ['numeric', '2'],
                ['numeric', '2'],
            ]],
            ['/', '/Index'],
            ['[', [
                ['numeric', '0'],
                ['numeric', '1'],
            ]],
            ['/', '/DecodeParms'],
            ['<<', [
                ['/', '/Columns'],
                ['numeric', '4'],
                ['/', '/Predictor'],
                ['numeric', '12'], // PNG Up
            ]],
        ];

        // Should not throw exception
        $this->xrefStreamParser->parseStream($streamData, $streamDict, $pdfXrefTable);

        // Verify that parsing completed (even if predictor decoding may not work perfectly without real data)
        $this->assertInstanceOf(PDFXrefTable::class, $pdfXrefTable);
        // Note: With PNG predictor and test data, the result may not be perfect,
        // but we verify the method doesn't crash
    }

    public function testParseStreamWithZeroWidthFields(): void
    {
        $pdfXrefTable = new PDFXrefTable;

        // W = [0, 2, 2] means type defaults to 1
        $streamData = "\x00\x64\x00\x00\x00\xC8\x00\x00";

        $streamDict = [
            ['/', '/Type'],
            ['/', '/XRef'],
            ['/', '/W'],
            ['[', [
                ['numeric', '0'],
                ['numeric', '2'],
                ['numeric', '2'],
            ]],
            ['/', '/Index'],
            ['[', [
                ['numeric', '0'],
                ['numeric', '2'],
            ]],
        ];

        $this->xrefStreamParser->parseStream($streamData, $streamDict, $pdfXrefTable);

        // Entries should default to type 1 (in-use)
        $entry0 = $pdfXrefTable->getEntry(0);
        $this->assertNotNull($entry0);
        $this->assertSame(100, $entry0->getOffset());
    }
}
