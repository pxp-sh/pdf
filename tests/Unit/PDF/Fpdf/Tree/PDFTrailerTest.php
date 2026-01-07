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
        $pdfTrailer = new PDFTrailer;
        $pdfTrailer->setSize(10);
        $this->assertSame(10, $pdfTrailer->getSize());
    }

    public function testGetSetRoot(): void
    {
        $pdfTrailer   = new PDFTrailer;
        $pdfReference = new PDFReference(5, 0);
        $pdfTrailer->setRoot($pdfReference);

        $this->assertSame($pdfReference, $pdfTrailer->getRoot());
    }

    public function testGetSetInfo(): void
    {
        $pdfTrailer   = new PDFTrailer;
        $pdfReference = new PDFReference(3, 0);
        $pdfTrailer->setInfo($pdfReference);

        $this->assertSame($pdfReference, $pdfTrailer->getInfo());
    }

    public function testGetSetEncrypt(): void
    {
        $pdfTrailer   = new PDFTrailer;
        $pdfReference = new PDFReference(7, 0);
        $pdfTrailer->setEncrypt($pdfReference);

        $this->assertSame($pdfReference, $pdfTrailer->getEncrypt());
    }

    public function testGetSetId(): void
    {
        $pdfTrailer = new PDFTrailer;
        $id         = ['abc123', 'def456'];
        $pdfTrailer->setId($id);

        $this->assertSame($id, $pdfTrailer->getId());
    }

    public function testToDictionary(): void
    {
        $pdfTrailer = new PDFTrailer;
        $pdfTrailer->setSize(10);
        $pdfTrailer->setRoot(new PDFReference(5, 0));

        $pdfDictionary = $pdfTrailer->toDictionary();
        $this->assertInstanceOf(PDFDictionary::class, $pdfDictionary);
        $this->assertNotNull($pdfDictionary->getEntry('/Size'));
        $this->assertNotNull($pdfDictionary->getEntry('/Root'));
    }

    public function testSerialize(): void
    {
        $pdfTrailer = new PDFTrailer;
        $pdfTrailer->setSize(10);
        $pdfTrailer->setRoot(new PDFReference(5, 0));

        $result = $pdfTrailer->serialize(1000);
        $this->assertStringContainsString('trailer', $result);
        $this->assertStringContainsString('startxref', $result);
        $this->assertStringContainsString('1000', $result);
        $this->assertStringEndsWith("%%EOF\n", $result);
    }
}
