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

require_once __DIR__ . '/../vendor/autoload.php';

use PXP\PDF\Fpdf\Extractor\Text;
use PXP\PDF\Fpdf\FileReaders\DefaultFileReader;

// Path to a sample PDF
$pdfPath = __DIR__ . '/../tests/resources/PDF/input/23-grande.pdf';

// Create extractor
$text = new Text(new DefaultFileReader);

print "=== Easy Buffer Extraction Example ===\n\n";

// Example 1: Auto-optimized extraction (automatically chooses buffer vs file)
print "1. Auto-Optimized Extraction (page 5):\n";
$content = $text->extractOptimized($pdfPath, 5);
print '   Extracted ' . \strlen($content) . " characters\n";
print '   First 100 chars: ' . \substr($content, 0, 100) . "...\n\n";

// Example 2: Create a small buffer from specific pages
print "2. Creating Small Buffer (pages 10-12):\n";
$bufferPath = $text->createSmallBuffer($pdfPath, [10, 11, 12]);
print '   Buffer created: ' . $bufferPath . "\n";
print '   Buffer size: ' . \round(\filesize($bufferPath) / 1024 / 1024, 2) . " MB\n";

// Extract from the buffer
$bufferContent = $text->extractFromString(\file_get_contents($bufferPath));
print '   Extracted ' . \strlen($bufferContent) . " characters from buffer\n";
@\unlink($bufferPath); // Cleanup
print "\n";

// Example 3: Extract with buffer info (includes metadata)
print "3. Extract with Buffer Info (pages 15-17):\n";
$result = $text->extractFromFileWithBufferInfo($pdfPath, 15, 17);
print '   Extracted ' . \strlen($result['text']) . " characters\n";
print '   Buffer size: ' . \round($result['buffer_size'] / 1024 / 1024, 2) . " MB\n";
print '   Buffer pages: ' . $result['page_count'] . "\n";
print '   Performance: ' . \round($result['duration_ms'], 2) . " ms\n";
@\unlink($result['buffer_path']); // Cleanup
print "\n";

// Example 4: Decision helper - should we use buffer or file?
print "4. Buffer Decision Helper:\n";
$fileSize   = \filesize($pdfPath);
$totalPages = $text->getPageCount($pdfPath);

// Check if buffer is recommended for 5 pages, used once
$shouldBuffer1 = $text->shouldUseBuffer($fileSize, 5, $totalPages, 1);
print '   Extract 5 pages (1 time): Use buffer? ' . ($shouldBuffer1 ? 'YES' : 'NO') . "\n";

// Check if buffer is recommended for 5 pages, reused 3 times
$shouldBuffer2 = $text->shouldUseBuffer($fileSize, 5, $totalPages, 3);
print '   Extract 5 pages (3 times): Use buffer? ' . ($shouldBuffer2 ? 'YES' : 'NO') . "\n";

// Check if buffer is recommended for 300 pages (>50% of document)
$shouldBuffer3 = $text->shouldUseBuffer($fileSize, 300, $totalPages, 1);
print '   Extract 300 pages (>50%): Use buffer? ' . ($shouldBuffer3 ? 'YES' : 'NO') . "\n";

print "\n=== Decision Logic ===\n";
print "- Use buffer if: file < 5MB OR pages > 50% OR reusage > 1\n";
print "- Use file directly if: small subset of a large file, used once\n";

print "\n=== Summary ===\n";
print "New convenience methods make buffer management easier:\n";
print "- extractOptimized(): Automatically chooses best strategy\n";
print "- createSmallBuffer(): Easily create page-specific buffers\n";
print "- extractFromFileWithBufferInfo(): Get text + performance metrics\n";
print "- shouldUseBuffer(): Decision helper for custom logic\n";
