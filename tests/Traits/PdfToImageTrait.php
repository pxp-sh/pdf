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
namespace Test;

use const PATHINFO_FILENAME;
use function dirname;
use function escapeshellarg;
use function exec;
use function file_exists;
use function glob;
use function implode;
use function is_dir;
use function is_readable;
use function mkdir;
use function pathinfo;
use function realpath;
use function rename;
use function sprintf;
use function uniqid;
use RuntimeException;

trait PdfToImageTrait
{
    /**
     * Convert PDF page to image using external CLI tool.
     *
     * @param string      $pdfPath    Path to PDF file
     * @param int         $pageNumber Page number (1-based)
     * @param null|string $outputPath Optional output path for image (default: auto-generated)
     *
     * @throws RuntimeException If no suitable tool is available or conversion fails
     *
     * @return string Path to generated image file
     */
    public static function pdfToImage(string $pdfPath, int $pageNumber = 1, ?string $outputPath = null): string
    {
        // Check file exists first, then normalize path
        if (!file_exists($pdfPath)) {
            throw new RuntimeException('PDF file not found: ' . $pdfPath);
        }

        // Normalize path (realpath returns false if file doesn't exist, but we already checked)
        $normalizedPath = realpath($pdfPath);
        $pdfPath        = $normalizedPath !== false ? $normalizedPath : $pdfPath;

        if ($outputPath === null) {
            $outputPath = self::getRootDir() . '/pdf_image_' . uniqid() . '_page' . $pageNumber . '.png';
        }

        // Ensure output directory exists
        $outputDir = dirname($outputPath);

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0o777, true);
        }

        // Try mutool first (MuPDF) - best for Core fonts, doesn't require system fonts
        if (self::commandExists('mutool')) {
            return self::pdfToImageWithMutool($pdfPath, $pageNumber, $outputPath);
        }

        // Try pdftoppm (poppler-utils)
        if (self::commandExists('pdftoppm')) {
            return self::pdfToImageWithPdftoppm($pdfPath, $pageNumber, $outputPath);
        }

        // Try ImageMagick convert
        if (self::commandExists('convert')) {
            return self::pdfToImageWithImageMagick($pdfPath, $pageNumber, $outputPath);
        }

        // Try Ghostscript
        if (self::commandExists('gs')) {
            return self::pdfToImageWithGhostscript($pdfPath, $pageNumber, $outputPath);
        }

        $error = 'No PDF to image conversion tool found. Please install one of: mutool (MuPDF), ' .
            'pdftoppm (poppler-utils), ImageMagick (convert), or Ghostscript (gs)';

        throw new RuntimeException($error);
    }

    /**
     * Convert PDF to image using MuPDF's mutool.
     * MuPDF has built-in Core font support and doesn't require system fonts.
     */
    private static function pdfToImageWithMutool(string $pdfPath, int $pageNumber, string $outputPath): string
    {
        // Verify PDF file exists and is readable
        if (!file_exists($pdfPath) || !is_readable($pdfPath)) {
            throw new RuntimeException('PDF file does not exist or is not readable: ' . $pdfPath);
        }

        // Get the actual page count of the PDF
        $actualPageCount = self::getPdfPageCount($pdfPath);

        // Adjust page number if it exceeds the PDF's page count
        // For single-page PDFs (like split pages), always use page 1
        // For multi-page PDFs, use the last page if requested page is out of range
        if ($actualPageCount === 1) {
            $actualPageNumber = 1;
        } elseif ($pageNumber > $actualPageCount) {
            $actualPageNumber = $actualPageCount;
        } else {
            $actualPageNumber = $pageNumber;
        }

        // Ensure output directory exists
        $outputDir = dirname($outputPath);

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0o777, true);
        }

        // mutool draw: mutool draw -o <output> -F <format> -r <dpi> <pdf> <page-range>
        // -F png: explicitly specify PNG output format
        // -r 200: resolution in DPI
        // -o: output file
        // Page numbers are 1-based
        $command = sprintf(
            'mutool draw -o %s -F png -r 200 %s %d 2>&1',
            escapeshellarg($outputPath),
            escapeshellarg($pdfPath),
            $actualPageNumber,
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $errorMsg = implode("\n", $output);

            throw new RuntimeException(
                'mutool failed (exit code ' . $returnCode . '): ' . $errorMsg .
                ' | PDF: ' . $pdfPath . ' | Command: ' . $command,
            );
        }

        if (!file_exists($outputPath)) {
            throw new RuntimeException('mutool did not generate expected image file: ' . $outputPath);
        }

        return $outputPath;
    }

    /**
     * Convert PDF to image using pdftoppm.
     */
    private static function pdfToImageWithPdftoppm(string $pdfPath, int $pageNumber, string $outputPath): string
    {
        // Verify PDF file exists and is readable
        if (!file_exists($pdfPath) || !is_readable($pdfPath)) {
            throw new RuntimeException('PDF file does not exist or is not readable: ' . $pdfPath);
        }

        // Get the actual page count of the PDF
        $actualPageCount = self::getPdfPageCount($pdfPath);

        // Adjust page number if it exceeds the PDF's page count
        // For single-page PDFs (like split pages), always use page 1
        // For multi-page PDFs, use the last page if requested page is out of range
        if ($actualPageCount === 1) {
            $actualPageNumber = 1;
        } elseif ($pageNumber > $actualPageCount) {
            $actualPageNumber = $actualPageCount;
        } else {
            $actualPageNumber = $pageNumber;
        }

        $baseName = pathinfo($outputPath, PATHINFO_FILENAME);
        $dir      = dirname($outputPath);

        // Ensure output directory exists
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        $outputPrefix = $dir . '/' . $baseName;

        // pdftoppm command: pdftoppm -png -f <first> -l <last> -r <dpi> <pdf> <output-prefix>
        // Use higher DPI (200) for better quality and font rendering
        // -freetype yes: Enable FreeType font rendering
        // Note: Core fonts like Helvetica may show "Couldn't find a font" warnings on stderr,
        // but these are non-fatal - pdftoppm will still render (may use substitute fonts)
        // We suppress stderr warnings but check return code for actual errors
        $command = sprintf(
            'pdftoppm -png -f %d -l %d -r 200 -freetype yes %s %s 2>/dev/null',
            $actualPageNumber,
            $actualPageNumber,
            escapeshellarg($pdfPath),
            escapeshellarg($outputPrefix),
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $errorMsg = implode("\n", $output);

            throw new RuntimeException(
                'pdftoppm failed (exit code ' . $returnCode . '): ' . $errorMsg .
                ' | PDF: ' . $pdfPath . ' | Command: ' . $command,
            );
        }

        // pdftoppm generates files with zero-padded suffixes: -0001.png, -0002.png, etc.
        // Try both formats: -1.png and -0001.png
        // Use actualPageNumber since that's what pdftoppm used to generate the file
        $generatedFile       = $outputPrefix . '-' . $actualPageNumber . '.png';
        $generatedFilePadded = $outputPrefix . '-' . sprintf('%04d', $actualPageNumber) . '.png';

        // Check for zero-padded format first (most common)
        if (file_exists($generatedFilePadded)) {
            // Rename to desired output path
            if ($generatedFilePadded !== $outputPath && !rename($generatedFilePadded, $outputPath)) {
                throw new RuntimeException('Failed to rename generated image file from ' . $generatedFilePadded . ' to ' . $outputPath);
            }

            return $outputPath;
        }

        // Check for non-padded format
        if (file_exists($generatedFile)) {
            // Rename to desired output path
            if ($generatedFile !== $outputPath && !rename($generatedFile, $outputPath)) {
                throw new RuntimeException('Failed to rename generated image file from ' . $generatedFile . ' to ' . $outputPath);
            }

            return $outputPath;
        }

        // List files in directory for debugging
        $dirFiles = glob($dir . '/' . $baseName . '-*.png');

        throw new RuntimeException(
            'pdftoppm did not generate expected image file. Tried: ' . $generatedFile . ' and ' . $generatedFilePadded .
            ' (found files: ' . implode(', ', $dirFiles ?: []) . ')',
        );
    }

    /**
     * Convert PDF to image using ImageMagick convert.
     */
    private static function pdfToImageWithImageMagick(string $pdfPath, int $pageNumber, string $outputPath): string
    {
        // Get the actual page count of the PDF
        $actualPageCount = self::getPdfPageCount($pdfPath);

        // Adjust page number if it exceeds the PDF's page count
        // For single-page PDFs (like split pages), always use page 1
        // For multi-page PDFs, use the last page if requested page is out of range
        if ($actualPageCount === 1) {
            $actualPageNumber = 1;
        } elseif ($pageNumber > $actualPageCount) {
            $actualPageNumber = $actualPageCount;
        } else {
            $actualPageNumber = $pageNumber;
        }

        // escapeshellarg already adds quotes, so don't add extra quotes
        $command = sprintf(
            'convert -density 150 %s[%d] %s 2>&1',
            escapeshellarg($pdfPath),
            $actualPageNumber - 1, // ImageMagick uses 0-based indexing
            escapeshellarg($outputPath),
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new RuntimeException('ImageMagick convert failed: ' . implode("\n", $output));
        }

        if (!file_exists($outputPath)) {
            throw new RuntimeException('ImageMagick did not generate expected image file');
        }

        return $outputPath;
    }

    /**
     * Convert PDF to image using Ghostscript.
     */
    private static function pdfToImageWithGhostscript(string $pdfPath, int $pageNumber, string $outputPath): string
    {
        // Get the actual page count of the PDF
        $actualPageCount = self::getPdfPageCount($pdfPath);

        // Adjust page number if it exceeds the PDF's page count
        // For single-page PDFs (like split pages), always use page 1
        // For multi-page PDFs, use the last page if requested page is out of range
        if ($actualPageCount === 1) {
            $actualPageNumber = 1;
        } elseif ($pageNumber > $actualPageCount) {
            $actualPageNumber = $actualPageCount;
        } else {
            $actualPageNumber = $pageNumber;
        }

        // escapeshellarg already adds quotes, so don't add extra quotes
        $command = sprintf(
            'gs -dNOPAUSE -dBATCH -sDEVICE=png16m -dFirstPage=%d -dLastPage=%d -r150 -sOutputFile=%s %s 2>&1',
            $actualPageNumber,
            $actualPageNumber,
            escapeshellarg($outputPath),
            escapeshellarg($pdfPath),
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new RuntimeException('Ghostscript failed: ' . implode("\n", $output));
        }

        if (!file_exists($outputPath)) {
            throw new RuntimeException('Ghostscript did not generate expected image file');
        }

        return $outputPath;
    }
}
