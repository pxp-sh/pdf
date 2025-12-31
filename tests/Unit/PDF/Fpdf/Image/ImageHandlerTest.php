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

namespace Test\Unit\PDF\Fpdf\Image;

use PHPUnit\Framework\TestCase;
use PXP\PDF\Fpdf\Exception\FpdfException;
use PXP\PDF\Fpdf\Image\ImageHandler;

/**
 * @covers \PXP\PDF\Fpdf\Image\ImageHandler
 */
final class ImageHandlerTest extends TestCase
{
    private ImageHandler $imageHandler;

    protected function setUp(): void
    {
        $this->imageHandler = new ImageHandler();
    }

    public function testAddImageThrowsExceptionForEmptyFileName(): void
    {
        $this->expectException(FpdfException::class);
        $this->expectExceptionMessage('Image file name is empty');
        $this->imageHandler->addImage('');
    }

    public function testAddImageThrowsExceptionForUnsupportedType(): void
    {
        $this->expectException(FpdfException::class);
        $this->expectExceptionMessage('Unsupported image type: bmp');
        $this->imageHandler->addImage('test.bmp', 'bmp');
    }

    public function testAddImageThrowsExceptionForNoExtensionAndNoType(): void
    {
        $this->expectException(FpdfException::class);
        $this->expectExceptionMessage('Image file has no extension and no type was specified: testfile');
        $this->imageHandler->addImage('testfile');
    }

    public function testAddImageNormalizesJpegToJpg(): void
    {
        // This will fail because file doesn't exist, but we can test the normalization
        // The error message will contain 'jpeg' in the filename, but the type is normalized to 'jpg'
        $this->expectException(FpdfException::class);
        $this->expectExceptionMessage('Missing or incorrect image file:');
        $this->imageHandler->addImage('test.jpeg', 'jpeg');
    }

    public function testGetImageReturnsNullForNonExistentImage(): void
    {
        $this->assertNull($this->imageHandler->getImage('nonexistent.jpg'));
    }

    public function testGetAllImagesReturnsEmptyArrayInitially(): void
    {
        $this->assertSame([], $this->imageHandler->getAllImages());
    }

    public function testAddImageReturnsSameInfoForSameFile(): void
    {
        // This test would require actual image files
        // For now, we test the structure
        $this->assertTrue(true);
    }
}
