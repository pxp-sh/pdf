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

use PXP\PDF\Fpdf\Core\Object\Base\PDFBoolean;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Object\Base\PDFBoolean
 */
final class PDFBooleanTest extends TestCase
{
    public function testToStringWithTrue(): void
    {
        $pdfBoolean = new PDFBoolean(true);
        $this->assertSame('true', (string) $pdfBoolean);
    }

    public function testToStringWithFalse(): void
    {
        $pdfBoolean = new PDFBoolean(false);
        $this->assertSame('false', (string) $pdfBoolean);
    }

    public function testGetValue(): void
    {
        $bool1 = new PDFBoolean(true);
        $bool2 = new PDFBoolean(false);

        $this->assertTrue($bool1->getValue());
        $this->assertFalse($bool2->getValue());
    }
}
