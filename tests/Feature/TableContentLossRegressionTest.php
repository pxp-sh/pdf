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
namespace Test\Feature;

use function escapeshellarg;
use function exec;
use function file_exists;
use function implode;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;
use Test\TestCase;

/**
 * Regression test for table content loss during PDF merge.
 *
 * This test uses pre-generated PDFs that exhibit the bug to ensure
 * the issue doesn't reoccur.
 *
 * @see docs/TABLE_CONTENT_LOSS_ANALYSIS.md
 */
final class TableContentLossRegressionTest extends TestCase
{
    /**
     * Test that demonstrates the table content loss bug with pre-generated files.
     *
     * The buggy files were generated with:
     * 1. Extracting page 530 from 23-grande.pdf (single page extraction - correct)
     * 2. Merging pages 530-534 from 23-grande.pdf
     *
     * The first page of the merged PDF is missing the first 3 columns of the table.
     */
    public function test_pre_generated_files_show_table_content_loss(): void
    {
        $correctFile = __DIR__ . '/../../var/tmp/tmp_extract_695b244bb0f6c/page_530.pdf';
        $buggyFile   = __DIR__ . '/../../var/tmp/tmp_extract_695b244bb0f6c/merged_last_5_pages.pdf';

        if (!file_exists($correctFile) || !file_exists($buggyFile)) {
            $this->markTestSkipped('Pre-generated test files not available');
        }

        $textCorrect = $this->extractTextFromPdf($correctFile);
        $textBuggy   = $this->extractTextFromPdf($buggyFile, pageNum: 1); // First page of merged PDF

        self::getLogger()->info('Correct file text length: ' . strlen($textCorrect));
        self::getLogger()->info('Correct file first 200 chars: ' . substr($textCorrect, 0, 200));
        self::getLogger()->info('Buggy file text length: ' . strlen($textBuggy));
        self::getLogger()->info('Buggy file first 200 chars: ' . substr($textBuggy, 0, 200));

        // These columns should be present in BOTH files
        $expectedColumns = [
            'TIPO DE SISTEMA',
            'TIPO DE INSTALAÇÃO',
            'INSTALAÇÕES',
        ];

        foreach ($expectedColumns as $expectedColumn) {
            // Remove spaces/newlines for comparison
            $columnNoSpace      = str_replace([' ', "\n"], '', $expectedColumn);
            $textCorrectNoSpace = str_replace([' ', "\n"], '', $textCorrect);
            $textBuggyNoSpace   = str_replace([' ', "\n"], '', $textBuggy);

            $this->assertStringContainsString(
                $columnNoSpace,
                $textCorrectNoSpace,
                "Correct PDF should contain column: {$expectedColumn}",
            );

            // THIS SHOULD FAIL - the buggy file is missing these columns
            $this->assertStringContainsString(
                $columnNoSpace,
                $textBuggyNoSpace,
                "Merged PDF should contain column: {$expectedColumn} (BUG: This is missing!)",
            );
        }
    }

    /**
     * Extract text content from a PDF using pdftotext.
     */
    private function extractTextFromPdf(string $pdfPath, int $pageNum = 1): string
    {
        if (!file_exists($pdfPath)) {
            return '';
        }

        $output    = [];
        $returnVar = 0;
        exec(
            sprintf('pdftotext -f %d -l %d %s -', $pageNum, $pageNum, escapeshellarg($pdfPath)),
            $output,
            $returnVar,
        );

        if ($returnVar !== 0) {
            exec(sprintf('pdftotext %s -', escapeshellarg($pdfPath)), $output, $returnVar);
        }

        return implode("\n", $output);
    }
}
