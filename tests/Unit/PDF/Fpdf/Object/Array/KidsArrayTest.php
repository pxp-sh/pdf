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
        $kids = new KidsArray;
        $kids->addPage(3);

        $this->assertSame(1, $kids->count());
        $page = $kids->getPage(0);
        $this->assertInstanceOf(PDFReference::class, $page);
        $this->assertSame(3, $page->getObjectNumber());
    }

    public function testAddPageWithGeneration(): void
    {
        $kids = new KidsArray;
        $kids->addPage(3, 5);

        $page = $kids->getPage(0);
        $this->assertSame(5, $page->getGenerationNumber());
    }

    public function testGetPageNumbers(): void
    {
        $kids = new KidsArray;
        $kids->addPage(3);
        $kids->addPage(5);
        $kids->addPage(7);

        $numbers = $kids->getPageNumbers();
        $this->assertSame([3, 5, 7], $numbers);
    }

    public function testGetPageReturnsNullForInvalidIndex(): void
    {
        $kids = new KidsArray;
        $this->assertNull($kids->getPage(999));
    }
}
