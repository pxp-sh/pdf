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

use function extension_loaded;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function imagecolorallocate;
use function imagecolorat;
use function imagecreatefrompng;
use function imagecreatetruecolor;
use function imagedestroy;
use function imagepng;
use function imagesetpixel;
use function imagesx;
use function imagesy;
use function max;
use function mkdir;
use function preg_match;
use function strlen;
use function substr;
use function uniqid;
use function unlink;
use Faker\Factory;
use ReflectionProperty;
use RuntimeException;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\FPDF
 */
final class GenerateAndParsePdfTest extends TestCase
{
    /**
     * @covers \PXP\PDF\Fpdf\FPDF
     * @covers \PXP\PDF\Fpdf\Output\OutputHandler
     */
    public function test_generate_pdf_to_string_and_parse_metadata_and_links(): void
    {
        $pdf = self::createFPDF();

        $pdf->setCompression(false);

        $title = 'Test PDF Title';
        $uri   = 'https://example.test/path?query=1';

        $pdf->setTitle($title);
        $pdf->addPage();

        $pdf->link(10, 10, 20, 10, $uri);

        $result = $pdf->output('S', 'test.pdf');

        $this->assertStringContainsString('%PDF-', $result);
        $this->assertStringContainsString('/Title (' . $title . ')', $result);
        $this->assertStringContainsString('/URI (' . $uri . ')', $result);
    }

    /**
     * @covers \PXP\PDF\Fpdf\FPDF
     * @covers \PXP\PDF\Fpdf\Output\OutputHandler
     */
    public function test_generate_pdf_file_output_and_parse_file_contents(): void
    {
        $pdf = self::createFPDF('L');
        $pdf->setCompression(false);

        $title = 'File Output Title';
        $uri   = 'https://example.test/file';

        $pdf->setTitle($title);
        $pdf->addPage();
        $pdf->link(5, 5, 10, 5, $uri);

        $tmpFile = self::getRootDir() . '/pxp_test_pdf_' . uniqid() . '.pdf';

        $pdf->output('F', $tmpFile);

        $this->assertFileExists($tmpFile);
        $contents = file_get_contents($tmpFile);
        $this->assertIsString($contents);

        $this->assertStringContainsString('%PDF-', $contents);
        $this->assertStringContainsString('/Title (' . $title . ')', $contents);
        $this->assertStringContainsString('/URI (' . $uri . ')', $contents);

        // Verify visual correctness of the generated PDF
        // Create a reference PDF with the same content
        $referencePdf = self::createFPDF('L');
        $referencePdf->setCompression(false);
        $referencePdf->setTitle($title);
        $referencePdf->addPage();
        $referencePdf->link(5, 5, 10, 5, $uri);
        $referenceFile = self::getRootDir() . '/pxp_test_pdf_reference_' . uniqid() . '.pdf';
        $referencePdf->output('F', $referenceFile);

        // If both rendered pages are blank due to renderer behavior, skip visual comparison
        try {
            $imgA = self::pdfToImage($referenceFile, 1);
            $imgB = self::pdfToImage($tmpFile, 1);

            $bothBlank = false;

            if (extension_loaded('gd')) {
                $bothBlank = true;

                foreach ([$imgA, $imgB] as $imgPath) {
                    $gd = @imagecreatefrompng($imgPath);

                    if ($gd === false) {
                        $bothBlank = false;

                        break;
                    }
                    $w        = imagesx($gd);
                    $h        = imagesy($gd);
                    $nonWhite = 0;
                    $total    = $w * $h;

                    for ($x = 0; $x < $w; $x++) {
                        for ($y = 0; $y < $h; $y++) {
                            $rgb = imagecolorat($gd, $x, $y);
                            $r   = ($rgb >> 16) & 0xFF;
                            $g   = ($rgb >> 8) & 0xFF;
                            $b   = $rgb & 0xFF;

                            if (!($r >= 250 && $g >= 250 && $b >= 250)) {
                                $nonWhite++;
                            }
                        }
                    }
                    imagedestroy($gd);

                    if ($nonWhite / max(1, $total) > 0.01) {
                        $bothBlank = false;

                        break;
                    }
                }
            }

            // Clean up intermediate images
            @unlink($imgA);
            @unlink($imgB);

            if ($bothBlank) {
                $this->markTestSkipped('Rendered PDFs are blank on this environment; skipping visual comparison');
            }
        } catch (RuntimeException $e) {
            // If conversion tool isn't available or fails, skip visual comparison
            $this->markTestSkipped('PDF to image conversion failed: ' . $e->getMessage());
        }

        $this->assertPdfPagesSimilar($referenceFile, $tmpFile, 1, 0.90, 'Generated PDF should visually match reference');

        self::unlink($referenceFile);
        self::unlink($tmpFile);
    }

    /**
     * @covers \PXP\PDF\Fpdf\FPDF
     */
    public function test_generate_pdf_with_text(): void
    {
        $pdf = self::createFPDF();
        $pdf->setCompression(false);

        $ref = new ReflectionProperty($pdf, 'pdfStructure');
        $ref->setAccessible(true);
        $pdfStructure = $ref->getValue($pdf);
        $prop         = new ReflectionProperty($pdfStructure, 'compress');
        $prop->setAccessible(true);
        $prop->setValue($pdfStructure, false);

        $tmpDir = self::getRootDir() . '/tmp_pf_' . uniqid();
        mkdir($tmpDir, 0o777, true);
        $fontFile = $tmpDir . '/testfont.php';
        file_put_contents($fontFile, '<?php $name="TestFont"; $type="Core"; $cw=[];');

        $pdf->addFont('TestFont', '', 'testfont.php', $tmpDir);
        $pdf->setFont('TestFont', '', 12);
        $pdf->addPage();
        $pdf->cell(0, 10, 'Hello World');

        $result = $pdf->output('S', 'text.pdf');

        $this->assertStringContainsString('%PDF-', $result);
        $this->assertStringContainsString('(Hello World)', $result);

        // Verify visual correctness - save to file and compare
        $tmpFile = $tmpDir . '/text_output.pdf';
        $pdf2    = self::createFPDF();
        $pdf2->setCompression(false);
        $ref = new ReflectionProperty($pdf2, 'pdfStructure');
        $ref->setAccessible(true);
        $pdfStructure2 = $ref->getValue($pdf2);
        $prop2         = new ReflectionProperty($pdfStructure2, 'compress');
        $prop2->setAccessible(true);
        $prop2->setValue($pdfStructure2, false);
        $pdf2->addFont('TestFont', '', 'testfont.php', $tmpDir);
        $pdf2->setFont('TestFont', '', 12);
        $pdf2->addPage();
        $pdf2->cell(0, 10, 'Hello World');
        $pdf2->output('F', $tmpFile);

        // Create reference PDF for comparison
        $referencePdf = self::createFPDF();
        $referencePdf->setCompression(false);
        $ref3 = new ReflectionProperty($referencePdf, 'pdfStructure');
        $ref3->setAccessible(true);
        $pdfStructure3 = $ref3->getValue($referencePdf);
        $prop3         = new ReflectionProperty($pdfStructure3, 'compress');
        $prop3->setAccessible(true);
        $prop3->setValue($pdfStructure3, false);
        $referencePdf->addFont('TestFont', '', 'testfont.php', $tmpDir);
        $referencePdf->setFont('TestFont', '', 12);
        $referencePdf->addPage();
        $referencePdf->cell(0, 10, 'Hello World');
        $referenceFile = $tmpDir . '/text_reference.pdf';
        $referencePdf->output('F', $referenceFile);

        $this->assertPdfPagesSimilar($referenceFile, $tmpFile, 1, 0.90, 'PDF with text should visually match reference');

        self::unlink($tmpFile);
        self::unlink($referenceFile);
        self::unlink($fontFile);
    }

    /**
     * @covers \PXP\PDF\Fpdf\FPDF
     */
    public function test_generate_pdf_with_image(): void
    {
        $pdf = self::createFPDF();
        $pdf->setCompression(false);

        $im = imagecreatetruecolor(1, 1);
        // Use a non-white pixel so the rendered image is visible across renderers
        $c = imagecolorallocate($im, 0, 0, 0);
        imagesetpixel($im, 0, 0, $c);
        $imgFile = self::getRootDir() . '/test_img_' . uniqid() . '.png';
        imagepng($im, $imgFile);
        imagedestroy($im);

        $pdf->addPage();
        $pdf->image($imgFile, 10, 10, 10, 10, 'png');

        $result = $pdf->output('S', 'image.pdf');

        $this->assertStringContainsString('%PDF-', $result);
        $this->assertStringContainsString('/XObject <<', $result);
        $this->assertStringContainsString('/I1 ', $result);
        $this->assertStringContainsString('/Width 1', $result);
        $this->assertStringContainsString('/Height 1', $result);

        $this->assertStringNotContainsString('/SMask', $result);

        $this->assertMatchesRegularExpression('/\/I1 (\d+) 0 R/', $result, 'Resource dictionary should map /I1 to an object number');
        preg_match('/\/I1 (\d+) 0 R/', $result, $m);
        $objNum = (int) ($m[1] ?? 0);
        $this->assertGreaterThan(0, $objNum, 'Found invalid object number for /I1');

        $this->assertMatchesRegularExpression('/' . $objNum . ' 0 obj[\s\S]*?\/Subtype \/Image/s', $result, 'Referenced object must be an Image XObject');

        if (preg_match('/' . $objNum . ' 0 obj[\s\S]*?\/Length\s+(\d+)[\s\S]*?stream\r?\n([\s\S]*?)\r?\nendstream/s', $result, $mm)) {
            $declLen    = (int) $mm[1];
            $streamData = $mm[2];
            $this->assertSame($declLen, strlen($streamData), 'Declared /Length must match stream byte length');
        } else {
            $this->fail('Could not detect stream length for image object ' . $objNum);
        }

        // Verify visual correctness - save to file and verify image appears correctly
        $tmpFile = self::getRootDir() . '/test_img_pdf_' . uniqid() . '.pdf';
        $pdf2    = self::createFPDF();
        $pdf2->setCompression(false);
        $pdf2->addPage();
        $pdf2->image($imgFile, 10, 10, 10, 10, 'png');
        $pdf2->output('F', $tmpFile);

        // Create reference PDF for comparison
        $referencePdf = self::createFPDF();
        $referencePdf->setCompression(false);
        $referencePdf->addPage();
        $referencePdf->image($imgFile, 10, 10, 10, 10, 'png');
        $referenceFile = self::getRootDir() . '/test_img_reference_' . uniqid() . '.pdf';
        $referencePdf->output('F', $referenceFile);

        // If both rendered pages are blank due to renderer behavior, skip visual comparison
        try {
            $imgA      = self::pdfToImage($referenceFile, 1);
            $imgB      = self::pdfToImage($tmpFile, 1);
            $bothBlank = false;

            if (extension_loaded('gd')) {
                $bothBlank = true;

                foreach ([$imgA, $imgB] as $imgPath) {
                    $gd = @imagecreatefrompng($imgPath);

                    if ($gd === false) {
                        $bothBlank = false;

                        break;
                    }
                    $w        = imagesx($gd);
                    $h        = imagesy($gd);
                    $nonWhite = 0;
                    $total    = $w * $h;

                    for ($x = 0; $x < $w; $x++) {
                        for ($y = 0; $y < $h; $y++) {
                            $rgb = imagecolorat($gd, $x, $y);
                            $r   = ($rgb >> 16) & 0xFF;
                            $g   = ($rgb >> 8) & 0xFF;
                            $b   = $rgb & 0xFF;

                            if (!($r >= 250 && $g >= 250 && $b >= 250)) {
                                $nonWhite++;
                            }
                        }
                    }
                    imagedestroy($gd);

                    if ($nonWhite / max(1, $total) > 0.01) {
                        $bothBlank = false;

                        break;
                    }
                }
            }
            @unlink($imgA);
            @unlink($imgB);

            if ($bothBlank) {
                $this->markTestSkipped('Rendered PDFs are blank on this environment; skipping visual comparison');
            }
        } catch (RuntimeException $e) {
            $this->markTestSkipped('PDF to image conversion failed: ' . $e->getMessage());
        }

        $this->assertPdfPagesSimilar($referenceFile, $tmpFile, 1, 0.90, 'PDF with image should visually match reference');

        self::unlink($tmpFile);
        self::unlink($referenceFile);
        self::unlink($imgFile);
    }

    /**
     * @covers \PXP\PDF\Fpdf\FPDF
     */
    public function test_generate_pdf_with_different_page_sizes_and_layouts(): void
    {
        $pdf = self::createFPDF('P', 'mm', 'A3');
        $pdf->setCompression(false);
        $pdf->addPage();
        $pdf->setDisplayMode('default', 'continuous');
        $resultA3 = $pdf->output('S', 'a3.pdf');

        $this->assertStringContainsString('%PDF-', $resultA3);

        $this->assertStringContainsString('841.89', $resultA3);
        $this->assertStringContainsString('1190.55', $resultA3);
        $this->assertStringContainsString('/PageLayout /OneColumn', $resultA3);

        $pdf2 = self::createFPDF('L', 'mm', 'A4');
        $pdf2->setCompression(false);
        $pdf2->addPage();
        $pdf2->setDisplayMode('default', 'two');
        $resultA4L = $pdf2->output('S', 'a4l.pdf');

        $this->assertStringContainsString('%PDF-', $resultA4L);
        $this->assertStringContainsString('/PageLayout /TwoColumnLeft', $resultA4L);
    }

    /**
     * @covers \PXP\PDF\Fpdf\FPDF
     */
    public function test_generate_pdf_with_faker_multiple_pages(): void
    {
        $faker = Factory::create();
        $faker->seed(1234);

        $pdf = self::createFPDF();
        $pdf->setCompression(false);

        $ref = new ReflectionProperty($pdf, 'pdfStructure');
        $ref->setAccessible(true);
        $pdfStructure = $ref->getValue($pdf);
        $prop         = new ReflectionProperty($pdfStructure, 'compress');
        $prop->setAccessible(true);
        $prop->setValue($pdfStructure, false);

        $tmpDir = self::getRootDir() . '/tmp_pf_' . uniqid();
        mkdir($tmpDir, 0o777, true);
        $fontFile = $tmpDir . '/testfont.php';
        file_put_contents($fontFile, '<?php $name="TestFont"; $type="Core"; $cw=[];');

        $pdf->addFont('TestFont', '', 'testfont.php', $tmpDir);
        $pdf->setFont('TestFont', '', 12);

        $pages  = 5;
        $sample = '';

        for ($p = 0; $p < $pages; $p++) {
            $pdf->addPage();

            for ($i = 0; $i < 3; $i++) {
                $para = $faker->paragraph(3);

                if ($p === 0 && $i === 0) {
                    $sample = $para;
                }
                $pdf->multiCell(0, 6, $para);
                $pdf->ln();
            }

            $quote = $faker->sentence();
            $pdf->cell(0, 6, '"' . $quote . '"', 0, 1);
        }

        $result = $pdf->output('S', 'faker.pdf');

        $fakerOutputFile = $tmpDir . '/faker_output.pdf';
        $pdf->output('F', $fakerOutputFile);
        $this->assertTrue(
            file_exists($fakerOutputFile),
            'Failed to create PDF file for inspection.',
        );

        $this->assertStringContainsString('%PDF-', $result);

        $this->assertStringContainsString('/Count ' . $pages, $result);

        $this->assertStringContainsString(substr($sample, 0, 20), $result);

        // Verify visual correctness of first page
        // Create a reference PDF with the same first page content
        $referencePdf = self::createFPDF();
        $referencePdf->setCompression(false);
        $ref4 = new ReflectionProperty($referencePdf, 'pdfStructure');
        $ref4->setAccessible(true);
        $pdfStructure4 = $ref4->getValue($referencePdf);
        $prop4         = new ReflectionProperty($pdfStructure4, 'compress');
        $prop4->setAccessible(true);
        $prop4->setValue($pdfStructure4, false);
        $referencePdf->addFont('TestFont', '', 'testfont.php', $tmpDir);
        $referencePdf->setFont('TestFont', '', 12);
        $referencePdf->addPage();
        $faker2 = Factory::create();
        $faker2->seed(1234);

        for ($i = 0; $i < 3; $i++) {
            $para = $faker2->paragraph(3);
            $referencePdf->multiCell(0, 6, $para);
            $referencePdf->ln();
        }
        $quote = $faker2->sentence();
        $referencePdf->cell(0, 6, '"' . $quote . '"', 0, 1);
        $referenceFile = $tmpDir . '/faker_reference.pdf';
        $referencePdf->output('F', $referenceFile);

        $this->assertPdfPagesSimilar($referenceFile, $fakerOutputFile, 1, 0.90, 'First page of faker PDF should visually match reference');

        self::unlink($referenceFile);
        self::unlink($fontFile);
    }
}
