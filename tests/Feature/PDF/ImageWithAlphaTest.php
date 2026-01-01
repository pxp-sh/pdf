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

namespace Test\Feature\PDF;

use Test\TestCase;
use PXP\PDF\Fpdf\FPDF;

/**
 * @covers \PXP\PDF\Fpdf\FPDF
 */
final class ImageWithAlphaTest extends TestCase
{
    public function test_embed_png_with_alpha_creates_smask(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD not available');
        }


        $im = imagecreatetruecolor(2, 2);
        imagesavealpha($im, true);
        $bg = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefilledrectangle($im, 0, 0, 1, 1, $bg);
        $c = imagecolorallocatealpha($im, 255, 0, 0, 0);
        imagesetpixel($im, 1, 1, $c);
        $file = self::getRootDir() . '/alpha_test_' . uniqid() . '.png';
        imagepng($im, $file);
        imagedestroy($im);

        $pdf = self::createFPDF();
        $pdf->setCompression(false);
        $pdf->addPage();
        $pdf->image($file, 10, 10, 10, 10, 'png');

        $result = $pdf->output('S', 'alpha.pdf');

        $this->assertStringContainsString('/SMask ', $result);

        preg_match('/\/I1 (\d+) 0 R/', $result, $m);
        $this->assertNotEmpty($m, 'Expected image resource mapping');
        $obj = (int) ($m[1] ?? 0);
        $this->assertGreaterThan(0, $obj);

        $this->assertMatchesRegularExpression('/' . $obj . ' 0 obj[\s\S]*?\/SMask\s+(\d+)\s+0\s+R/s', $result, 'Image object should reference an SMask object');

        // Verify visual correctness - save to file and verify alpha channel is preserved
        $tmpFile = self::getRootDir() . '/alpha_test_pdf_' . uniqid() . '.pdf';
        $pdf2 = self::createFPDF();
        $pdf2->setCompression(false);
        $pdf2->addPage();
        $pdf2->image($file, 10, 10, 10, 10, 'png');
        $pdf2->output('F', $tmpFile);

        // Create reference PDF for comparison
        $referencePdf = self::createFPDF();
        $referencePdf->setCompression(false);
        $referencePdf->addPage();
        $referencePdf->image($file, 10, 10, 10, 10, 'png');
        $referenceFile = self::getRootDir() . '/alpha_test_reference_' . uniqid() . '.pdf';
        $referencePdf->output('F', $referenceFile);

        $this->assertPdfPagesSimilar($referenceFile, $tmpFile, 1, 0.90, 'PDF with alpha channel image should visually match reference');

        self::unlink($tmpFile);
        self::unlink($referenceFile);
        self::unlink($file);
    }
}
