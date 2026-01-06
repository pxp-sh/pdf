<?php declare(strict_types=1);

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

// Check command line arguments
if ($argc < 2) {
    print "Usage: php extract_and_save.php <input.pdf> [output.txt]\n";
    print "\nAlternatively, run without arguments for a demo.\n\n";

    // Demo mode - create a sample PDF
    print "Running in demo mode...\n\n";

    $pdf = new FPDF;
    $pdf->addPage();
    $pdf->setFont('Arial', 'B', 18);
    $pdf->cell(0, 12, 'Demo Document', 0, 1, 'C');
    $pdf->ln(5);

    $pdf->setFont('Arial', '', 11);
    $pdf->multiCell(
        0,
        5,
        'This is a demonstration of extracting text from a PDF file and saving it to a text file. '
        . 'The extracted content can then be used for text analysis, searching, indexing, or any other text processing task.',
    );
    $pdf->ln(5);

    $pdf->setFont('Arial', 'B', 12);
    $pdf->cell(0, 8, 'Key Benefits:', 0, 1);
    $pdf->setFont('Arial', '', 10);
    $pdf->cell(10, 6, '', 0, 0);
    $pdf->cell(0, 6, '1. Convert PDF content to searchable text', 0, 1);
    $pdf->cell(10, 6, '', 0, 0);
    $pdf->cell(0, 6, '2. Enable text analysis and processing', 0, 1);
    $pdf->cell(10, 6, '', 0, 0);
    $pdf->cell(0, 6, '3. Archive content in plain text format', 0, 1);

    $pdfContent = $pdf->output('S', 'demo.pdf');
    $inputFile  = '/tmp/demo_extract.pdf';
    \file_put_contents($inputFile, $pdfContent);
    $outputFile = '/tmp/demo_extract.txt';

    print "Created demo PDF: {$inputFile}\n";
} else {
    $inputFile  = $argv[1];
    $outputFile = $argv[2] ?? \str_replace('.pdf', '.txt', $inputFile);
}

// Verify input file exists
if (!\file_exists($inputFile)) {
    print "Error: File not found: {$inputFile}\n";

    exit(1);
}

print "Input PDF:  {$inputFile}\n";
print "Output TXT: {$outputFile}\n\n";

// Extract text from PDF
print "Extracting text...\n";
$extractor = new Text;
$startTime = \microtime(true);
$text      = $extractor->extractFromFile($inputFile);
$duration  = (\microtime(true) - $startTime) * 1000;

print 'Extracted ' . \strlen($text) . ' characters in ' . \round($duration, 2) . " ms\n\n";

// Save to text file
\file_put_contents($outputFile, $text);
print "Saved to: {$outputFile}\n\n";

// Show preview
if ($text !== '') {
    print "Preview (first 300 characters):\n";
    print \str_repeat('-', 60) . "\n";
    print \substr($text, 0, 300);

    if (\strlen($text) > 300) {
        print '...';
    }
    print "\n" . \str_repeat('-', 60) . "\n";
} else {
    print "No text was extracted from the PDF.\n";
    print "The PDF might be image-based or encrypted.\n";
}

print "\nDone!\n";
