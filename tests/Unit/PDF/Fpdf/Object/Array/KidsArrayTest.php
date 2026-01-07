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
namespace Test\Unit\PDF\Fpdf\Object\Array;

use PXP\PDF\Fpdf\Core\Object\Array\KidsArray;
use PXP\PDF\Fpdf\Core\Object\Base\PDFReference;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Object\Array\KidsArray
 */
final class KidsArrayTest extends TestCase
{
    public function testAddPage(): void
    {
        $kidsArray = new KidsArray;
        $kidsArray->addPage(3);

        $this->assertSame(1, $kidsArray->count());
        $page = $kidsArray->getPage(0);
        $this->assertInstanceOf(PDFReference::class, $page);
        $this->assertSame(3, $page->getObjectNumber());
    }

    public function testAddPageWithGeneration(): void
    {
        $kidsArray = new KidsArray;
        $kidsArray->addPage(3, 5);

        $page = $kidsArray->getPage(0);
        $this->assertSame(5, $page->getGenerationNumber());
    }

    public function testGetPageNumbers(): void
    {
        $kidsArray = new KidsArray;
        $kidsArray->addPage(3);
        $kidsArray->addPage(5);
        $kidsArray->addPage(7);

        $numbers = $kidsArray->getPageNumbers();
        $this->assertSame([3, 5, 7], $numbers);
    }

    public function testGetPageReturnsNullForInvalidIndex(): void
    {
        $kidsArray = new KidsArray;
        $this->assertNull($kidsArray->getPage(999));
    }
}
