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

use PXP\PDF\Fpdf\Core\Xref\XrefEntry;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Xref\XrefEntry
 */
final class XrefEntryTest extends TestCase
{
    public function testGetOffset(): void
    {
        $entry = new XrefEntry(100, 0, false);
        $this->assertSame(100, $entry->getOffset());
    }

    public function testSetOffset(): void
    {
        $entry = new XrefEntry(100);
        $entry->setOffset(200);
        $this->assertSame(200, $entry->getOffset());
    }

    public function testGetGeneration(): void
    {
        $entry = new XrefEntry(100, 5, false);
        $this->assertSame(5, $entry->getGeneration());
    }

    public function testIsFree(): void
    {
        $entry1 = new XrefEntry(100, 0, false);
        $entry2 = new XrefEntry(100, 0, true);

        $this->assertFalse($entry1->isFree());
        $this->assertTrue($entry2->isFree());
    }

    public function testToStringForNormalEntry(): void
    {
        $entry  = new XrefEntry(100, 0, false);
        $result = (string) $entry;
        $this->assertStringContainsString('100', $result);
        $this->assertStringContainsString('n', $result);
    }

    public function testToStringForFreeEntry(): void
    {
        $entry  = new XrefEntry(100, 0, true);
        $result = (string) $entry;
        $this->assertStringContainsString('f', $result);
    }
}
