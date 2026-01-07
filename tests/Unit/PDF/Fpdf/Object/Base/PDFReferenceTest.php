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
        $ref = new PDFReference(3, 0);
        $this->assertSame('3 0 R', (string) $ref);
    }

    public function testToStringWithCustomGeneration(): void
    {
        $ref = new PDFReference(3, 5);
        $this->assertSame('3 5 R', (string) $ref);
    }

    public function testGetObjectNumber(): void
    {
        $ref = new PDFReference(42, 0);
        $this->assertSame(42, $ref->getObjectNumber());
    }

    public function testGetGenerationNumber(): void
    {
        $ref = new PDFReference(3, 5);
        $this->assertSame(5, $ref->getGenerationNumber());
    }

    public function testDefaultGenerationIsZero(): void
    {
        $ref = new PDFReference(3);
        $this->assertSame(0, $ref->getGenerationNumber());
        $this->assertSame('3 0 R', (string) $ref);
    }
}
