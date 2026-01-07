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
namespace Test\Unit\PDF\Fpdf\Object\Base;

use PXP\PDF\Fpdf\Core\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Core\Object\Base\PDFName;
use PXP\PDF\Fpdf\Core\Object\Base\PDFNumber;
use PXP\PDF\Fpdf\Core\Object\Base\PDFString;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Object\Base\PDFDictionary
 */
final class PDFDictionaryTest extends TestCase
{
    public function testToStringWithEmptyDictionary(): void
    {
        $pdfDictionary = new PDFDictionary;
        $result        = (string) $pdfDictionary;
        $this->assertStringStartsWith('<<', $result);
        $this->assertStringEndsWith('>>', $result);
    }

    public function testAddEntry(): void
    {
        $pdfDictionary = new PDFDictionary;
        $pdfDictionary->addEntry('/Type', new PDFName('Page'));

        $this->assertTrue($pdfDictionary->hasEntry('/Type'));
        $entry = $pdfDictionary->getEntry('/Type');
        $this->assertInstanceOf(PDFName::class, $entry);
    }

    public function testAddEntryWithStringKey(): void
    {
        $pdfDictionary = new PDFDictionary;
        $pdfDictionary->addEntry('Type', new PDFName('Page'));

        $this->assertTrue($pdfDictionary->hasEntry('/Type'));
    }

    public function testAddEntryAutoConvertsValues(): void
    {
        $pdfDictionary = new PDFDictionary;
        $pdfDictionary->addEntry('/Count', 42);
        $pdfDictionary->addEntry('/Title', 'Test');

        $count = $pdfDictionary->getEntry('/Count');
        $this->assertInstanceOf(PDFNumber::class, $count);
        $this->assertSame(42, $count->getValue());

        $title = $pdfDictionary->getEntry('/Title');
        $this->assertInstanceOf(PDFString::class, $title);
    }

    public function testGetEntryReturnsNullForMissingKey(): void
    {
        $pdfDictionary = new PDFDictionary;
        $this->assertNull($pdfDictionary->getEntry('/Missing'));
    }

    public function testRemoveEntry(): void
    {
        $pdfDictionary = new PDFDictionary;
        $pdfDictionary->addEntry('/Type', new PDFName('Page'));
        $pdfDictionary->removeEntry('/Type');

        $this->assertFalse($pdfDictionary->hasEntry('/Type'));
    }

    public function testConstructorWithEntries(): void
    {
        $pdfDictionary = new PDFDictionary([
            '/Type'  => new PDFName('Page'),
            '/Count' => new PDFNumber(1),
        ]);

        $this->assertTrue($pdfDictionary->hasEntry('/Type'));
        $this->assertTrue($pdfDictionary->hasEntry('/Count'));
    }

    public function testToStringWithMultipleEntries(): void
    {
        $pdfDictionary = new PDFDictionary;
        $pdfDictionary->addEntry('/Type', new PDFName('Page'));
        $pdfDictionary->addEntry('/Count', new PDFNumber(1));

        $result = (string) $pdfDictionary;
        $this->assertStringContainsString('/Type', $result);
        $this->assertStringContainsString('/Count', $result);
        $this->assertStringContainsString('Page', $result);
        $this->assertStringContainsString('1', $result);
    }
}
