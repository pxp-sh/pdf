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
namespace Test\Traits;

use const PHP_OS;
use function escapeshellarg;
use function exec;
use function extension_loaded;
use function imagecolorat;
use function imagedestroy;
use function imagesx;
use function imagesy;
use function implode;
use function in_array;
use function max;
use function preg_match;
use function preg_replace;
use function proc_close;
use function proc_open;
use function sprintf;
use function stream_get_contents;
use function trim;

trait GeneralUtilitiesTrait
{
    /**
     * Check if a PDF page has text content.
     *
     * @param string $pdfPath    Path to PDF file
     * @param int    $pageNumber Page number (1-based)
     *
     * @return bool True if page has text content
     */
    protected function pdfHasTextContent(string $pdfPath, int $pageNumber): bool
    {
        $text = $this->extractPdfText($pdfPath, $pageNumber);

        return !in_array(trim($text), ['', '0'], true);
    }

    /**
     * Extract text content from a PDF page.
     *
     * @param string $pdfPath    Path to PDF file
     * @param int    $pageNumber Page number (1-based)
     *
     * @return string Extracted text content
     */
    protected function extractPdfText(string $pdfPath, int $pageNumber): string
    {
        // Try using pdftotext if available
        if (self::commandExists('pdftotext')) {
            $command = sprintf(
                'pdftotext -f %d -l %d %s - 2>&1',
                $pageNumber,
                $pageNumber,
                escapeshellarg($pdfPath),
            );
            exec($command, $output, $returnCode);
            $text = implode("\n", $output);

            // Remove excessive whitespace but keep content
            return trim((string) preg_replace('/\s+/', ' ', $text));
        }

        // Fallback: return empty (assume no text extraction available)
        return '';
    }

    /**
     * Get the number of pages in a PDF file.
     *
     * @param string $pdfPath Path to PDF file
     *
     * @return int Number of pages (1 if unable to determine, as safe default)
     */
    protected static function getPdfPageCount(string $pdfPath): int
    {
        // Try using pdfinfo if available (poppler-utils)
        if (self::commandExists('pdfinfo')) {
            $command = sprintf('pdfinfo %s 2>&1', escapeshellarg($pdfPath));
            exec($command, $output, $returnCode);

            if ($returnCode === 0) {
                $outputStr = implode("\n", $output);

                // Look for "Pages: N" in the output
                if (preg_match('/Pages:\s*(\d+)/i', $outputStr, $matches)) {
                    return (int) $matches[1];
                }
            }
        }

        // Fallback: try pdftoppm with a high page number to get error message with page count
        // pdftoppm will fail with "Wrong page range" if page number is too high
        // We can parse the error to get the actual page count, or try incremental approach
        if (self::commandExists('pdftoppm')) {
            // Try to extract a very high page number - pdftoppm will tell us the max
            $testCommand = sprintf('pdftoppm -png -f 9999 -l 9999 %s /tmp/pdf_pagecount_test 2>&1', escapeshellarg($pdfPath));
            exec($testCommand, $testOutput, $testReturnCode);

            if ($testReturnCode !== 0) {
                $errorMsg = implode("\n", $testOutput);

                // Look for "last page (N)" in error message
                if (preg_match('/last page \((\d+)\)/i', $errorMsg, $matches)) {
                    return (int) $matches[1];
                }

                // Look for "can not be after the last page \((\d+)\)"
                if (preg_match('/last page \((\d+)\)/i', $errorMsg, $matches)) {
                    return (int) $matches[1];
                }
            }
        }

        // If we can't determine, return 1 as safe default
        // The calling code will handle single-page PDFs correctly
        return 1;
    }

    /**
     * Check if an image is mostly white (blank page detection).
     *
     * @param string $imagePath Path to image file
     *
     * @return bool True if image is >99% white
     */
    protected static function isImageMostlyWhite(string $imagePath): bool
    {
        if (!extension_loaded('gd')) {
            return false;
        }

        $img = self::loadImage($imagePath);

        if ($img === null) {
            return false;
        }

        $width          = imagesx($img);
        $height         = imagesy($img);
        $nonWhitePixels = 0;

        // Sample pixels (check every 10th pixel for performance)
        for ($x = 0; $x < $width; $x += 10) {
            for ($y = 0; $y < $height; $y += 10) {
                $rgb = imagecolorat($img, $x, $y);
                $r   = ($rgb >> 16) & 0xFF;
                $g   = ($rgb >> 8) & 0xFF;
                $b   = $rgb & 0xFF;

                if (!($r >= 250 && $g >= 250 && $b >= 250)) {
                    $nonWhitePixels++;
                }
            }
        }

        imagedestroy($img);

        $sampledPixels = (int) (($width / 10) * ($height / 10));
        $whiteRatio    = 1.0 - ($nonWhitePixels / max(1, $sampledPixels));

        return $whiteRatio > 0.99; // >99% white is considered blank
    }

    /**
     * Check if a command exists in PATH.
     */
    protected static function commandExists(string $command): bool
    {
        $whereIsCommand = (PHP_OS === 'WINNT') ? 'where' : 'which';

        $process = proc_open(
            "{$whereIsCommand} {$command}",
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if ($process === false) {
            return false;
        }

        $stdout     = stream_get_contents($pipes[1]);
        $returnCode = proc_close($process);

        return $returnCode === 0 && !in_array(trim($stdout), ['', '0'], true);
    }
}
