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
namespace Test\Unit\PDF\Fpdf\Tree;

use PXP\PDF\Fpdf\Core\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Core\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Core\Tree\PDFTrailer;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Tree\PDFTrailer
 */
final class PDFTrailerTest extends TestCase
{
    public function testGetSetSize(): void
    {
        $trailer = new PDFTrailer;
        $trailer->setSize(10);
        $this->assertSame(10, $trailer->getSize());
    }

    public function testGetSetRoot(): void
    {
        $trailer = new PDFTrailer;
        $ref     = new PDFReference(5, 0);
        $trailer->setRoot($ref);

        $this->assertSame($ref, $trailer->getRoot());
    }

    public function testGetSetInfo(): void
    {
        $trailer = new PDFTrailer;
        $ref     = new PDFReference(3, 0);
        $trailer->setInfo($ref);

        $this->assertSame($ref, $trailer->getInfo());
    }

    public function testGetSetEncrypt(): void
    {
        $trailer = new PDFTrailer;
        $ref     = new PDFReference(7, 0);
        $trailer->setEncrypt($ref);

        $this->assertSame($ref, $trailer->getEncrypt());
    }

    public function testGetSetId(): void
    {
        $trailer = new PDFTrailer;
        $id      = ['abc123', 'def456'];
        $trailer->setId($id);

        $this->assertSame($id, $trailer->getId());
    }

    public function testToDictionary(): void
    {
        $trailer = new PDFTrailer;
        $trailer->setSize(10);
        $trailer->setRoot(new PDFReference(5, 0));

        $dict = $trailer->toDictionary();
        $this->assertInstanceOf(PDFDictionary::class, $dict);
        $this->assertNotNull($dict->getEntry('/Size'));
        $this->assertNotNull($dict->getEntry('/Root'));
    }

    public function testSerialize(): void
    {
        $trailer = new PDFTrailer;
        $trailer->setSize(10);
        $trailer->setRoot(new PDFReference(5, 0));

        $result = $trailer->serialize(1000);
        $this->assertStringContainsString('trailer', $result);
        $this->assertStringContainsString('startxref', $result);
        $this->assertStringContainsString('1000', $result);
        $this->assertStringEndsWith("%%EOF\n", $result);
    }
}
