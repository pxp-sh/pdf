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

use PXP\PDF\Fpdf\Core\Object\Base\PDFNull;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Object\Base\PDFNull
 */
final class PDFNullTest extends TestCase
{
    public function testToString(): void
    {
        $null = new PDFNull;
        $this->assertSame('null', (string) $null);
    }
}
