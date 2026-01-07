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
namespace Test\Unit\PDF\Fpdf\Font;

use PXP\PDF\Fpdf\Rendering\Font\FontInfo;
use ReflectionClass;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Font\FontInfo
 */
final class FontInfoTest extends TestCase
{
    public function testFontInfoIsReadonly(): void
    {
        $reflection = new ReflectionClass(FontInfo::class);
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testFontInfoWithRequiredProperties(): void
    {
        $fontInfo = new FontInfo(
            name: 'Arial',
            type: 'TrueType',
            cw: ['a' => 500, 'b' => 600],
            i: 1,
        );

        $this->assertSame('Arial', $fontInfo->name);
        $this->assertSame('TrueType', $fontInfo->type);
        $this->assertSame(['a' => 500, 'b' => 600], $fontInfo->cw);
        $this->assertSame(1, $fontInfo->i);
        $this->assertSame([], $fontInfo->desc);
        $this->assertNull($fontInfo->file);
        $this->assertNull($fontInfo->enc);
        $this->assertNull($fontInfo->diff);
        $this->assertNull($fontInfo->uv);
        $this->assertFalse($fontInfo->subsetted);
        $this->assertNull($fontInfo->up);
        $this->assertNull($fontInfo->ut);
        $this->assertNull($fontInfo->originalsize);
        $this->assertNull($fontInfo->size1);
        $this->assertNull($fontInfo->size2);
    }

    public function testFontInfoWithAllProperties(): void
    {
        $desc     = ['Ascent' => 800, 'Descent' => -200];
        $uv       = [65 => 65, 66 => 66];
        $fontInfo = new FontInfo(
            name: 'Helvetica',
            type: 'Type1',
            cw: ['A' => 722, 'B' => 667],
            i: 2,
            desc: $desc,
            file: 'helvetica.php',
            enc: 'cp1252',
            diff: '32 /space',
            uv: $uv,
            subsetted: true,
            up: -100,
            ut: 50,
            originalsize: 12345,
            size1: 1000,
            size2: 2000,
        );

        $this->assertSame('Helvetica', $fontInfo->name);
        $this->assertSame('Type1', $fontInfo->type);
        $this->assertSame($desc, $fontInfo->desc);
        $this->assertSame('helvetica.php', $fontInfo->file);
        $this->assertSame('cp1252', $fontInfo->enc);
        $this->assertSame('32 /space', $fontInfo->diff);
        $this->assertSame($uv, $fontInfo->uv);
        $this->assertTrue($fontInfo->subsetted);
        $this->assertSame(-100, $fontInfo->up);
        $this->assertSame(50, $fontInfo->ut);
        $this->assertSame(12345, $fontInfo->originalsize);
        $this->assertSame(1000, $fontInfo->size1);
        $this->assertSame(2000, $fontInfo->size2);
    }
}
