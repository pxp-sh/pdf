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

namespace Test\Unit\PDF\Fpdf\Image\Parser;

use PXP\PDF\Fpdf\Image\Parser\PngParser;
use PXP\PDF\Fpdf\Exception\FpdfException;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Image\Parser\PngParser
 */
final class PngParserAlphaTest extends TestCase
{
    public function test_parse_png_with_alpha_produces_smask(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD not available');
        }

        $file = sys_get_temp_dir() . '/test_alpha_' . uniqid() . '.png';

        $im = imagecreatetruecolor(2, 2);
        imagesavealpha($im, true);

        $white = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 0, 0, 1, 1, $white);

        $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagesetpixel($im, 0, 0, $transparent);

        $c = imagecolorallocatealpha($im, 255, 0, 0, 0);
        imagesetpixel($im, 1, 1, $c);
        imagepng($im, $file);
        imagedestroy($im);


        $im2 = imagecreatefrompng($file);
        $a00 = (imagecolorat($im2, 0, 0) >> 24) & 0x7F;
        $a11 = (imagecolorat($im2, 1, 1) >> 24) & 0x7F;
        imagedestroy($im2);
        fwrite(STDERR, "DEBUG GD alphas (7-bit): $a00, $a11\n");

        $fileIO = self::createFileIO();
        $parser = new PngParser($fileIO, $fileIO);
        $info = $parser->parse($file);

        $this->assertArrayHasKey('data', $info);
        if (!isset($info['smask'])) {

            fwrite(STDERR, "DEBUG parse info: " . print_r($info, true) . "\n");
        }
        $this->assertArrayHasKey('smask', $info);
        $this->assertSame(2, $info['w']);
        $this->assertSame(2, $info['h']);

        $uncompressedSmask = gzuncompress($info['smask']);
        $this->assertSame(2 * (2 + 1), strlen($uncompressedSmask));

        @unlink($file);
    }

    public function test_parse_nonexistent_file_throws(): void
    {
        $this->expectException(FpdfException::class);
        $fileIO = self::createFileIO();
        $parser = new PngParser($fileIO, $fileIO);
        $parser->parse('/nonexistent/file.png');
    }
}
