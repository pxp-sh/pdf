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

use Test\TestCase;
use PXP\PDF\Fpdf\Xref\PDFXrefTable;
use PXP\PDF\Fpdf\Xref\XrefEntry;

/**
 * @covers \PXP\PDF\Fpdf\Xref\PDFXrefTable
 */
final class PDFXrefTableTest extends TestCase
{
    public function testAddEntry(): void
    {
        $xref = new PDFXrefTable();
        $xref->addEntry(1, 100, 0, false);

        $entry = $xref->getEntry(1);
        $this->assertNotNull($entry);
        $this->assertSame(100, $entry->getOffset());
        $this->assertSame(0, $entry->getGeneration());
        $this->assertFalse($entry->isFree());
    }

    public function testGetEntryReturnsNullForMissing(): void
    {
        $xref = new PDFXrefTable();
        $this->assertNull($xref->getEntry(999));
    }

    public function testUpdateOffset(): void
    {
        $xref = new PDFXrefTable();
        $xref->addEntry(1, 100);
        $xref->updateOffset(1, 200);

        $entry = $xref->getEntry(1);
        $this->assertNotNull($entry);
        $this->assertSame(200, $entry->getOffset());
    }

    public function testRebuild(): void
    {
        $xref = new PDFXrefTable();
        $xref->rebuild([
            1 => 100,
            2 => 200,
            3 => 300,
        ]);

        $this->assertNotNull($xref->getEntry(1));
        $this->assertNotNull($xref->getEntry(2));
        $this->assertNotNull($xref->getEntry(3));
        $this->assertSame(100, $xref->getEntry(1)->getOffset());
    }

    public function testSerialize(): void
    {
        $xref = new PDFXrefTable();
        $xref->addEntry(1, 100);
        $xref->addEntry(2, 200);

        $result = $xref->serialize();
        $this->assertStringStartsWith('xref', $result);
        $this->assertStringContainsString('100', $result);
        $this->assertStringContainsString('200', $result);
    }

    public function testParseFromString(): void
    {
        $xrefContent = "0 3\n0000000000 65535 f \n0000000100 00000 n \n0000000200 00000 n \n";
        $xref = new PDFXrefTable();
        $xref->parseFromString($xrefContent);

        $entry1 = $xref->getEntry(1);
        $this->assertNotNull($entry1);
        $this->assertSame(100, $entry1->getOffset());
    }
}
