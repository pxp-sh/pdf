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
        $dict   = new PDFDictionary;
        $result = (string) $dict;
        $this->assertStringStartsWith('<<', $result);
        $this->assertStringEndsWith('>>', $result);
    }

    public function testAddEntry(): void
    {
        $dict = new PDFDictionary;
        $dict->addEntry('/Type', new PDFName('Page'));

        $this->assertTrue($dict->hasEntry('/Type'));
        $entry = $dict->getEntry('/Type');
        $this->assertInstanceOf(PDFName::class, $entry);
    }

    public function testAddEntryWithStringKey(): void
    {
        $dict = new PDFDictionary;
        $dict->addEntry('Type', new PDFName('Page'));

        $this->assertTrue($dict->hasEntry('/Type'));
    }

    public function testAddEntryAutoConvertsValues(): void
    {
        $dict = new PDFDictionary;
        $dict->addEntry('/Count', 42);
        $dict->addEntry('/Title', 'Test');

        $count = $dict->getEntry('/Count');
        $this->assertInstanceOf(PDFNumber::class, $count);
        $this->assertSame(42, $count->getValue());

        $title = $dict->getEntry('/Title');
        $this->assertInstanceOf(PDFString::class, $title);
    }

    public function testGetEntryReturnsNullForMissingKey(): void
    {
        $dict = new PDFDictionary;
        $this->assertNull($dict->getEntry('/Missing'));
    }

    public function testRemoveEntry(): void
    {
        $dict = new PDFDictionary;
        $dict->addEntry('/Type', new PDFName('Page'));
        $dict->removeEntry('/Type');

        $this->assertFalse($dict->hasEntry('/Type'));
    }

    public function testConstructorWithEntries(): void
    {
        $dict = new PDFDictionary([
            '/Type'  => new PDFName('Page'),
            '/Count' => new PDFNumber(1),
        ]);

        $this->assertTrue($dict->hasEntry('/Type'));
        $this->assertTrue($dict->hasEntry('/Count'));
    }

    public function testToStringWithMultipleEntries(): void
    {
        $dict = new PDFDictionary;
        $dict->addEntry('/Type', new PDFName('Page'));
        $dict->addEntry('/Count', new PDFNumber(1));

        $result = (string) $dict;
        $this->assertStringContainsString('/Type', $result);
        $this->assertStringContainsString('/Count', $result);
        $this->assertStringContainsString('Page', $result);
        $this->assertStringContainsString('1', $result);
    }
}
