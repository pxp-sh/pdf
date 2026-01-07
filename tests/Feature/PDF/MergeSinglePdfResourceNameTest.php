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

use function dirname;
use function is_file;
use function mkdir;
use function sprintf;
use function str_contains;
use function uniqid;
use PXP\PDF\Fpdf\Features\Splitter\PDFMerger;
use PXP\PDF\Fpdf\IO\FileIO;
use RuntimeException;
use Test\TestCase;

final class MergeSinglePdfResourceNameTest extends TestCase
{
    public function test_resource_name_renaming_preserves_rendering(): void
    {
        $src = dirname(__DIR__, 2) . '/resources/input/1902.06656v2 (copy 1) (copy 1).pdf';

        if (!is_file($src)) {
            $this->markTestSkipped('Source PDF for regression test not found: ' . $src);
        }

        $tmpDir = self::getRootDir() . '/tmp_merge_' . uniqid();
        mkdir($tmpDir, 0o777, true);
        $out = $tmpDir . '/merged.pdf';

        $fileIO    = new FileIO(self::getLogger());
        $pdfMerger = new PDFMerger($fileIO, self::getLogger(), self::getEventDispatcher(), self::getCache());
        $pdfMerger->mergeIncremental([$src], $out);

        $this->assertFileExists($out);

        // Render page 2 and compare
        try {
            $imgOrig   = self::pdfToImage($src, 2);
            $imgMerged = self::pdfToImage($out, 2);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'No PDF to image conversion tool found')) {
                self::unlink($out);
                $this->markTestSkipped('PDF to image conversion not available: ' . $e->getMessage());
            }

            throw $e;
        }

        $similarity = self::compareImages($imgOrig, $imgMerged);

        self::unlink($imgOrig);
        self::unlink($imgMerged);
        self::unlink($out);

        $this->assertGreaterThanOrEqual(0.90, $similarity, sprintf('Expected similarity >= 0.90 but got %.6f', $similarity));
    }
}
