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
namespace PXP;

use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use PXP\PDF\Fpdf\Core\FPDF;
use PXP\PDF\Fpdf\Features\Extractor\Text;

/**
 * Facade class for the PXP PDF library.
 * Provides a simplified interface to PDF creation, manipulation, and extraction features.
 */
class PDF extends FPDF
{
    /**
     * Extract text content from a PDF file.
     *
     * @param string $filePath Path to the PDF file
     *
     * @return string Extracted text
     */
    public static function extractText(string $filePath): string
    {
        $extractor = new Text;

        return $extractor->extractFromFile($filePath);
    }

    /**
     * Extract text content from a PDF file using a streaming callback.
     * This method processes pages one by one and calls the callback for each page's text,
     * allowing for memory-efficient processing of large PDFs.
     *
     * @param callable $callback Function to call for each page: function(string $text, int $pageNumber): void
     * @param string   $filePath Path to the PDF file
     */
    public static function extractTextStreaming(callable $callback, string $filePath): void
    {
        $extractor = new Text;

        $extractor->extractFromFileStreaming($callback, $filePath);
    }

    /**
     * Split a PDF file into individual page files.
     *
     * @param string                        $pdfFilePath     Path to the PDF file to split
     * @param string                        $outputDir       Directory where split PDFs will be saved
     * @param null|string                   $filenamePattern Pattern for output filenames (use %d for page number, default: "page_%d.pdf")
     * @param null|LoggerInterface          $logger          Optional logger instance
     * @param null|CacheItemPoolInterface   $cacheItemPool   Optional cache instance
     * @param null|EventDispatcherInterface $eventDispatcher Optional event dispatcher instance
     *
     * @return array<string> Array of generated file paths
     */
    public static function splitPdf(
        string $pdfFilePath,
        string $outputDir,
        ?string $filenamePattern = null,
        ?LoggerInterface $logger = null,
        ?CacheItemPoolInterface $cacheItemPool = null,
        ?EventDispatcherInterface $eventDispatcher = null,
    ): array {
        return FPDF::splitPdf($pdfFilePath, $outputDir, $filenamePattern, $logger, $cacheItemPool, $eventDispatcher);
    }

    /**
     * Extract a single page from a PDF file.
     *
     * @param string                        $pdfFilePath     Path to the PDF file
     * @param int                           $pageNumber      Page number to extract (1-based)
     * @param string                        $outputPath      Path where the single-page PDF will be saved
     * @param null|LoggerInterface          $logger          Optional logger instance
     * @param null|CacheItemPoolInterface   $cacheItemPool   Optional cache instance
     * @param null|EventDispatcherInterface $eventDispatcher Optional event dispatcher instance
     */
    public static function extractPage(
        string $pdfFilePath,
        int $pageNumber,
        string $outputPath,
        ?LoggerInterface $logger = null,
        ?CacheItemPoolInterface $cacheItemPool = null,
        ?EventDispatcherInterface $eventDispatcher = null,
    ): void {
        FPDF::extractPage($pdfFilePath, $pageNumber, $outputPath, $logger, $cacheItemPool, $eventDispatcher);
    }

    /**
     * Merge multiple PDF files into a single PDF.
     *
     * @param array<string>                 $pdfFilePaths    Array of paths to PDF files to merge
     * @param string                        $outputPath      Path where the merged PDF will be saved
     * @param null|LoggerInterface          $logger          Optional logger instance
     * @param null|CacheItemPoolInterface   $cacheItemPool   Optional cache instance
     * @param null|EventDispatcherInterface $eventDispatcher Optional event dispatcher instance
     */
    public static function mergePdf(
        array $pdfFilePaths,
        string $outputPath,
        ?LoggerInterface $logger = null,
        ?CacheItemPoolInterface $cacheItemPool = null,
        ?EventDispatcherInterface $eventDispatcher = null,
    ): void {
        FPDF::mergePdf($pdfFilePaths, $outputPath, $logger, $cacheItemPool, $eventDispatcher);
    }
}
