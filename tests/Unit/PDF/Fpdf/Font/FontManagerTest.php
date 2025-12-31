<?php

declare(strict_types=1);

/**
 * Copyright (c) 2025 PXP
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/pxp-sh/pdf
 *
 */

namespace Test\Unit\PDF\Fpdf\Font;

use PHPUnit\Framework\TestCase;
use PXP\PDF\Fpdf\Exception\FpdfException;
use PXP\PDF\Fpdf\Font\FontManager;

/**
 * @covers \PXP\PDF\Fpdf\Font\FontManager
 */
final class FontManagerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/font_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    public function testConstructor(): void
    {
        $fontManager = new FontManager($this->tempDir);
        $this->assertSame([], $fontManager->getAllFonts());
        $this->assertSame([], $fontManager->getFontFiles());
        $this->assertSame([], $fontManager->getEncodings());
        $this->assertSame([], $fontManager->getCmaps());
    }

    public function testAddFontThrowsExceptionForInvalidFileName(): void
    {
        $fontManager = new FontManager($this->tempDir);
        $this->expectException(FpdfException::class);
        $this->expectExceptionMessage('Incorrect font definition file name: invalid/file.php');
        $fontManager->addFont('test', '', 'invalid/file.php');
    }

    public function testAddFontThrowsExceptionForInvalidFileNameWithBackslash(): void
    {
        $fontManager = new FontManager($this->tempDir);
        $this->expectException(FpdfException::class);
        $this->expectExceptionMessage('Incorrect font definition file name: invalid\\file.php');
        $fontManager->addFont('test', '', 'invalid\\file.php');
    }

    public function testAddFontThrowsExceptionForNonExistentFile(): void
    {
        $fontManager = new FontManager($this->tempDir);
        $this->expectException(FpdfException::class);
        $this->expectExceptionMessage('Could not include font definition file:');
        $fontManager->addFont('test', '', 'nonexistent.php');
    }

    public function testAddFontWithValidFile(): void
    {
        $fontFile = $this->tempDir . '/test.php';
        file_put_contents($fontFile, '<?php $name="TestFont"; $type="Core"; $cw=[];');

        $fontManager = new FontManager($this->tempDir);
        $fontManager->addFont('test', '', 'test.php');

        $fonts = $fontManager->getAllFonts();
        $this->assertCount(1, $fonts);
        $this->assertArrayHasKey('test', $fonts);
    }

    public function testAddFontNormalizesStyleIB(): void
    {
        $fontFile = $this->tempDir . '/testbi.php';
        file_put_contents($fontFile, '<?php $name="TestFont"; $type="Core"; $cw=[];');

        $fontManager = new FontManager($this->tempDir);
        $fontManager->addFont('test', 'IB', 'testbi.php');

        $fonts = $fontManager->getAllFonts();
        $this->assertArrayHasKey('testBI', $fonts);
    }

    public function testAddFontDoesNotAddDuplicate(): void
    {
        $fontFile = $this->tempDir . '/test.php';
        file_put_contents($fontFile, '<?php $name="TestFont"; $type="Core"; $cw=[];');

        $fontManager = new FontManager($this->tempDir);
        $fontManager->addFont('test', '', 'test.php');
        $fontManager->addFont('test', '', 'test.php');

        $fonts = $fontManager->getAllFonts();
        $this->assertCount(1, $fonts);
    }

    public function testGetFontWithArialMapsToHelvetica(): void
    {
        $fontFile = $this->tempDir . '/helvetica.php';
        file_put_contents($fontFile, '<?php $name="Helvetica"; $type="Core"; $cw=[];');

        $fontManager = new FontManager($this->tempDir);
        $font = $fontManager->getFont('arial');

        $this->assertNotNull($font);
        $this->assertArrayHasKey('name', $font);
    }

    public function testGetFontWithCoreFont(): void
    {
        $fontManager = new FontManager($this->tempDir);
        $this->expectException(FpdfException::class);
        $this->expectExceptionMessage('Could not include font definition file:');
        $fontManager->getFont('helvetica');
    }

    public function testGetFontThrowsExceptionForUndefinedFont(): void
    {
        $fontManager = new FontManager($this->tempDir);
        $this->expectException(FpdfException::class);
        $this->expectExceptionMessage('Undefined font: invalid ');
        $fontManager->getFont('invalid');
    }

    public function testGetFontNormalizesStyleIB(): void
    {
        // This test verifies that IB style is normalized to BI
        // Since helvetica is a core font, it will try to load it
        $fontManager = new FontManager($this->tempDir);
        $this->expectException(FpdfException::class);
        $this->expectExceptionMessage('Could not include font definition file:');
        $fontManager->getFont('helvetica', 'IB');
    }

    public function testGetFontWithSymbolRemovesStyle(): void
    {
        // Symbol font removes style, so it will try to load symbol.php
        $fontManager = new FontManager($this->tempDir);
        $this->expectException(FpdfException::class);
        $this->expectExceptionMessage('Could not include font definition file:');
        $fontManager->getFont('symbol', 'B');
    }

    public function testSetEncoding(): void
    {
        $fontManager = new FontManager($this->tempDir);
        $fontManager->setEncoding('cp1252', 5);

        $encodings = $fontManager->getEncodings();
        $this->assertArrayHasKey('cp1252', $encodings);
        $this->assertSame(5, $encodings['cp1252']);
    }

    public function testSetCmap(): void
    {
        $fontManager = new FontManager($this->tempDir);
        $fontManager->setCmap('test', 10);

        $cmaps = $fontManager->getCmaps();
        $this->assertArrayHasKey('test', $cmaps);
        $this->assertSame(10, $cmaps['test']);
    }

    public function testAddFontWithCustomDirectory(): void
    {
        $customDir = $this->tempDir . '/custom';
        mkdir($customDir, 0777, true);
        $fontFile = $customDir . '/test.php';
        file_put_contents($fontFile, '<?php $name="TestFont"; $type="Core"; $cw=[];');

        $fontManager = new FontManager($this->tempDir);
        $fontManager->addFont('test', '', 'test.php', $customDir);

        $fonts = $fontManager->getAllFonts();
        $this->assertCount(1, $fonts);
    }

    public function testAddFontWithDirectoryWithoutTrailingSlash(): void
    {
        $customDir = $this->tempDir . '/custom';
        mkdir($customDir, 0777, true);
        $fontFile = $customDir . '/test.php';
        file_put_contents($fontFile, '<?php $name="TestFont"; $type="Core"; $cw=[];');

        $fontManager = new FontManager($this->tempDir);
        $fontManager->addFont('test', '', 'test.php', rtrim($customDir, '/'));

        $fonts = $fontManager->getAllFonts();
        $this->assertCount(1, $fonts);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
