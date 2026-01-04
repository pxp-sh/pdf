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
namespace Test\Unit\PDF\Fpdf\Object\Dictionary;

use PXP\PDF\Fpdf\Object\Base\PDFArray;
use PXP\PDF\Fpdf\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Object\Dictionary\ResourcesDictionary;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Object\Dictionary\ResourcesDictionary
 */
final class ResourcesDictionaryTest extends TestCase
{
    public function testConstructorSetsProcSet(): void
    {
        $resources = new ResourcesDictionary;
        $procSet   = $resources->getEntry('/ProcSet');

        $this->assertNotNull($procSet);
        $this->assertInstanceOf(PDFArray::class, $procSet);
    }

    public function testAddFont(): void
    {
        $resources = new ResourcesDictionary;
        $resources->addFont('/F1', 5);

        $fonts = $resources->getFonts();
        $this->assertNotNull($fonts);
        $font1 = $fonts->getEntry('/F1');
        $this->assertInstanceOf(PDFReference::class, $font1);
        $this->assertSame(5, $font1->getObjectNumber());
    }

    public function testAddFontWithReference(): void
    {
        $resources = new ResourcesDictionary;
        $resources->addFont('/F1', new PDFReference(5));

        $fonts = $resources->getFonts();
        $this->assertNotNull($fonts);
    }

    public function testAddXObject(): void
    {
        $resources = new ResourcesDictionary;
        $resources->addXObject('/I1', 10);

        $xObjects = $resources->getXObjects();
        $this->assertNotNull($xObjects);
        $i1 = $xObjects->getEntry('/I1');
        $this->assertInstanceOf(PDFReference::class, $i1);
        $this->assertSame(10, $i1->getObjectNumber());
    }

    public function testSetProcSet(): void
    {
        $resources = new ResourcesDictionary;
        $resources->setProcSet(['PDF', 'Text']);

        $procSet = $resources->getEntry('/ProcSet');
        $this->assertInstanceOf(PDFArray::class, $procSet);
    }
}
