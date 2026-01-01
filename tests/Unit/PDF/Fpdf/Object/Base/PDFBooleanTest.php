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

use Test\TestCase;
use PXP\PDF\Fpdf\Object\Base\PDFBoolean;

/**
 * @covers \PXP\PDF\Fpdf\Object\Base\PDFBoolean
 */
final class PDFBooleanTest extends TestCase
{
    public function testToStringWithTrue(): void
    {
        $bool = new PDFBoolean(true);
        $this->assertSame('true', (string) $bool);
    }

    public function testToStringWithFalse(): void
    {
        $bool = new PDFBoolean(false);
        $this->assertSame('false', (string) $bool);
    }

    public function testGetValue(): void
    {
        $bool1 = new PDFBoolean(true);
        $bool2 = new PDFBoolean(false);

        $this->assertTrue($bool1->getValue());
        $this->assertFalse($bool2->getValue());
    }
}
