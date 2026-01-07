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

use PXP\PDF\Fpdf\Core\Object\Base\PDFReference;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Object\Base\PDFReference
 */
final class PDFReferenceTest extends TestCase
{
    public function testToStringReturnsReferenceFormat(): void
    {
        $pdfReference = new PDFReference(3, 0);
        $this->assertSame('3 0 R', (string) $pdfReference);
    }

    public function testToStringWithCustomGeneration(): void
    {
        $pdfReference = new PDFReference(3, 5);
        $this->assertSame('3 5 R', (string) $pdfReference);
    }

    public function testGetObjectNumber(): void
    {
        $pdfReference = new PDFReference(42, 0);
        $this->assertSame(42, $pdfReference->getObjectNumber());
    }

    public function testGetGenerationNumber(): void
    {
        $pdfReference = new PDFReference(3, 5);
        $this->assertSame(5, $pdfReference->getGenerationNumber());
    }

    public function testDefaultGenerationIsZero(): void
    {
        $pdfReference = new PDFReference(3);
        $this->assertSame(0, $pdfReference->getGenerationNumber());
        $this->assertSame('3 0 R', (string) $pdfReference);
    }
}
