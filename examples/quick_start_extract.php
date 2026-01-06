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

// Initialize the text extractor
$extractor = new Text;

// Method 1: Extract from a file path
$text = $extractor->extractFromFile('/path/to/your/document.pdf');
print $text;

// Method 2: Extract from PDF content (string)
$pdfContent = \file_get_contents('/path/to/your/document.pdf');
$text       = $extractor->extractFromString($pdfContent);
print $text;
