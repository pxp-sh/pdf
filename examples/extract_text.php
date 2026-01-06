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

require __DIR__ . '/../vendor/autoload.php';

use PXP\PDF\Fpdf\Extractor\Text;
use PXP\PDF\Fpdf\FPDF;

// ============================================================================
// Step 1: Create a multi-page sample PDF
// ============================================================================

print "Creating multi-page PDF...\n";

$pdf = new FPDF;

// Page 1
$pdf->addPage();
$pdf->setFont('Arial', 'B', 16);
$pdf->cell(0, 10, 'Page 1: Introduction', 0, 1);
$pdf->ln(5);
$pdf->setFont('Arial', '', 11);
$pdf->multiCell(0, 5, 'This is the first page of our document. It contains introductory information about the project.');

// Page 2
$pdf->addPage();
$pdf->setFont('Arial', 'B', 16);
$pdf->cell(0, 10, 'Page 2: Details', 0, 1);
$pdf->ln(5);
$pdf->setFont('Arial', '', 11);
$pdf->multiCell(0, 5, 'The second page contains detailed information about the implementation.');

// Page 3
$pdf->addPage();
$pdf->setFont('Arial', 'B', 16);
$pdf->cell(0, 10, 'Page 3: Conclusion', 0, 1);
$pdf->ln(5);
$pdf->setFont('Arial', '', 11);
$pdf->multiCell(0, 5, 'Finally, we conclude with a summary and next steps.');

$pdfPath = \sys_get_temp_dir() . '/multi_page_document.pdf';
$pdf->output('F', $pdfPath);

print "PDF created: {$pdfPath}\n\n";

// ============================================================================
// Step 2: Get page count
// ============================================================================

$extractor = new Text;
$pageCount = $extractor->getPageCount($pdfPath);

print "Total pages: {$pageCount}\n\n";

// ============================================================================
// Step 3: Extract text from a specific page
// ============================================================================

print "Extracting text from page 2...\n";
$page2Text = $extractor->extractFromFilePage($pdfPath, 2);

print "Page 2 text:\n";
print \str_repeat('-', 60) . "\n";
print $page2Text . "\n";
print \str_repeat('-', 60) . "\n\n";

// ============================================================================
// Step 4: Extract text from a page range
// ============================================================================

print "Extracting text from pages 1-3...\n";
$pageTexts = $extractor->extractFromFilePages($pdfPath, 1, 3);

foreach ($pageTexts as $pageNum => $text) {
    print "\nPage {$pageNum}:\n";
    print \str_repeat('-', 60) . "\n";
    print $text . "\n";
    print \str_repeat('-', 60) . "\n";
}

print "\n";

// ============================================================================
// Step 5: Process pages one by one and save to separate files
// ============================================================================

print "Saving each page to a separate text file...\n";

for ($i = 1; $i <= $pageCount; $i++) {
    $pageText = $extractor->extractFromFilePage($pdfPath, $i);
    $txtPath  = \sys_get_temp_dir() . "/page_{$i}.txt";
    \file_put_contents($txtPath, $pageText);
    print "- Page {$i} saved to: {$txtPath} (" . \strlen($pageText) . " characters)\n";
}

print "\nProcess completed successfully!\n";
