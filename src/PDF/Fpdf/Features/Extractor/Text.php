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
namespace PXP\PDF\Fpdf\Features\Extractor;

use const PREG_OFFSET_CAPTURE;
use const PREG_SET_ORDER;
use function count;
use function filesize;
use function implode;
use function max;
use function min;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function str_replace;
use function substr;
use function trim;
use function usort;
use Exception;
use InvalidArgumentException;
use PXP\PDF\Fpdf\Core\Object\Base\PDFArray;
use PXP\PDF\Fpdf\Core\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Core\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Core\Object\Parser\PDFParser;
use PXP\PDF\Fpdf\Core\Stream\PDFStream;
use PXP\PDF\Fpdf\Core\Tree\PDFDocument;
use PXP\PDF\Fpdf\Core\Tree\PDFObjectNode;
use PXP\PDF\Fpdf\Exception\FpdfException;
use PXP\PDF\Fpdf\IO\FileIO;
use PXP\PDF\Fpdf\IO\FileReaderInterface;
use RuntimeException;

/**
 * Extracts text content from PDF documents.
 */
class Text
{
    public function __construct(private readonly ?FileReaderInterface $fileReader = new FileIO, private readonly ?PDFParser $pdfParser = new PDFParser)
    {
    }

    /**
     * Extract text from a PDF file.
     *
     * @param string $filePath Path to the PDF file
     *
     * @throws FpdfException If the PDF cannot be parsed or pages cannot be retrieved
     *
     * @return string Extracted text
     */
    public function extractFromFile(string $filePath): string
    {
        // Use file-based parsing for better handling of complex PDFs (same approach as PDFSplitter)
        $document = $this->pdfParser->parseDocumentFromFile($filePath, $this->fileReader);

        return $this->extractTextFromDocument($document);
    }

    /**
     * Extract text from PDF content string.
     *
     * @param string $pdfContent PDF file content as string
     *
     * @return string Extracted text
     */
    public function extractFromString(string $pdfContent): string
    {
        $document = $this->pdfParser->parseDocument($pdfContent);

        return $this->extractTextFromDocument($document);
    }

    /**
     * Determine if buffer-based extraction is recommended for the given parameters.
     * This helps decide between file-based and buffer-based approaches.
     *
     * @param int $fileSize     Size of PDF file in bytes
     * @param int $pagesNeeded  Number of pages to extract
     * @param int $totalPages   Total pages in document
     * @param int $reusageCount How many times the buffer will be reused (default 1)
     *
     * @return bool True if buffer-based extraction is recommended
     */
    public function shouldUseBuffer(
        int $fileSize,
        int $pagesNeeded,
        int $totalPages,
        int $reusageCount = 1
    ): bool {
        // Buffer recommended if:
        // 1. File is small (< 5MB) - low memory overhead
        // 2. Extracting most pages (> 50%) - worth loading once
        // 3. Buffer will be reused multiple times (reusageCount > 1)
        // 4. Extracting all pages

        $smallFileThreshold = 5 * 1024 * 1024; // 5MB
        $pagePercentage     = $totalPages > 0 ? ($pagesNeeded / $totalPages) : 0;

        return
            $fileSize < $smallFileThreshold ||
            $pagePercentage > 0.5 ||
            $reusageCount > 1 ||
            $pagesNeeded === $totalPages;
    }

    /**
     * Extract text from a specific page of a PDF file.
     *
     * @param string $filePath   Path to the PDF file
     * @param int    $pageNumber Page number (1-based)
     *
     * @throws FpdfException If the PDF cannot be parsed or pages cannot be retrieved
     *
     * @return string Extracted text from the specified page
     */
    public function extractFromFilePage(string $filePath, int $pageNumber): string
    {
        // Use file-based parsing for better handling of complex PDFs (same approach as PDFSplitter)
        $document = $this->pdfParser->parseDocumentFromFile($filePath, $this->fileReader);

        return $this->extractTextFromDocumentPage($document, $pageNumber);
    }

    /**
     * Extract text with automatic optimization for large files.
     * This method automatically chooses the best extraction strategy based on file size.
     *
     * For small files (< 5MB): Loads entire file into memory
     * For large files: Uses direct file access (delegates to extractFromFilePage)
     *
     * @param string $filePath   Path to the PDF file
     * @param int    $pageNumber Page number (1-based), or null for all pages
     *
     * @return string Extracted text
     */
    public function extractOptimized(string $filePath, ?int $pageNumber = null): string
    {
        $fileSize = filesize($filePath);

        if ($fileSize === false) {
            throw new RuntimeException('Cannot determine file size: ' . $filePath);
        }

        // For single page extraction, always use page-specific method
        if ($pageNumber !== null) {
            return $this->extractFromFilePage($filePath, $pageNumber);
        }

        // For full extraction, use the standard method
        return $this->extractFromFile($filePath);
    }

    /**
     * Extract text from a specific page of PDF content.
     *
     * @param string $pdfContent PDF file content as string
     * @param int    $pageNumber Page number (1-based)
     *
     * @return string Extracted text from the specified page
     */
    public function extractFromStringPage(string $pdfContent, int $pageNumber): string
    {
        $document = $this->pdfParser->parseDocument($pdfContent);

        return $this->extractTextFromDocumentPage($document, $pageNumber);
    }

    /**
     * Extract text from a range of pages in a PDF file.
     *
     * @param string $filePath  Path to the PDF file
     * @param int    $startPage Start page number (1-based, inclusive)
     * @param int    $endPage   End page number (1-based, inclusive)
     *
     * @throws FpdfException If the PDF cannot be parsed or pages cannot be retrieved
     *
     * @return array<int, string> Array of page numbers => extracted text
     */
    public function extractFromFilePages(string $filePath, int $startPage, int $endPage): array
    {
        // Use file-based parsing for better handling of complex PDFs (same approach as PDFSplitter)
        $document = $this->pdfParser->parseDocumentFromFile($filePath, $this->fileReader);

        return $this->extractTextFromDocumentPages($document, $startPage, $endPage);
    }

    /**
     * Extract text from a range of pages in PDF content.
     *
     * @param string $pdfContent PDF file content as string
     * @param int    $startPage  Start page number (1-based, inclusive)
     * @param int    $endPage    End page number (1-based, inclusive)
     *
     * @return array<int, string> Array of page numbers => extracted text
     */
    public function extractFromStringPages(string $pdfContent, int $startPage, int $endPage): array
    {
        $document = $this->pdfParser->parseDocument($pdfContent);

        return $this->extractTextFromDocumentPages($document, $startPage, $endPage);
    }

    /**
     * Get the total number of pages in a PDF file.
     *
     * @param string $filePath Path to the PDF file
     *
     * @return int Number of pages
     */
    public function getPageCount(string $filePath): int
    {
        // Use file-based parsing for better handling of complex PDFs (same approach as PDFSplitter)
        $document = $this->pdfParser->parseDocumentFromFile($filePath, $this->fileReader);

        try {
            $pages = $document->getAllPages();

            return count($pages);
        } catch (Exception) {
            return 0;
        }
    }

    /**
     * Create a small PDF buffer containing only specified pages.
     * This is useful for efficient buffer-based extraction when you only need
     * to work with a subset of pages from a large PDF.
     *
     * Note: This method requires PDFSplitter and PDFMerger classes.
     * For simple single-page extraction, use extractFromFilePage() directly.
     *
     * @param string $filePath    Path to the PDF file
     * @param array  $pageNumbers Array of page numbers to include (1-based)
     *
     * @return string PDF content as string (small buffer)
     */
    public function createSmallBuffer(string $filePath, array $pageNumbers): string
    {
        if ($pageNumbers === []) {
            throw new InvalidArgumentException('Page numbers array cannot be empty');
        }

        // For single page, extract directly without merging
        if (count($pageNumbers) === 1) {
            return $this->extractSinglePageAsBuffer();
        }

        // For multiple pages, we need to use splitter/merger
        // This is left as a basic implementation - caller should use PDFSplitter/PDFMerger
        // for production use, or extract pages individually
        throw new RuntimeException(
            'Multiple page buffer creation requires PDFSplitter and PDFMerger. '
            . 'Extract pages individually or use those classes directly.',
        );
    }

    /**
     * Extract text from a page range and return both text and a small buffer.
     * This is a convenience method that combines buffer creation with text extraction.
     *
     * @param string $filePath  Path to the PDF file
     * @param int    $startPage Start page number (1-based, inclusive)
     * @param int    $endPage   End page number (1-based, inclusive)
     *
     * @throws FpdfException If the PDF cannot be parsed or pages cannot be retrieved
     *
     * @return array{text: array<int, string>, buffer_size: int} Extracted text and buffer size
     */
    public function extractFromFileWithBufferInfo(string $filePath, int $startPage, int $endPage): array
    {
        // Use file-based parsing for better handling of complex PDFs (same approach as PDFSplitter)
        $document = $this->pdfParser->parseDocumentFromFile($filePath, $this->fileReader);
        $text     = $this->extractTextFromDocumentPages($document, $startPage, $endPage);

        // Get file size for buffer info
        $bufferSize = filesize($filePath) ?: 0;

        return [
            'text'        => $text,
            'buffer_size' => $bufferSize,
        ];
    }

    /**
     * Extract a single page as a complete PDF buffer.
     * This creates a minimal, complete PDF containing just one page.
     *
     * @return string Complete PDF content containing just the specified page
     */
    private function extractSinglePageAsBuffer(): string
    {
        // For now, this requires external tools. In practice, you'd use PDFSplitter
        // This method serves as a placeholder for the interface design
        throw new RuntimeException(
            'Single page buffer extraction requires PDFSplitter. '
            . 'Use extractFromFilePage() for text extraction without buffer creation.',
        );
    }

    /**
     * Extract text from a PDFDocument object.
     *
     * @param PDFDocument $pdfDocument The PDF document
     *
     * @return string Extracted text
     */
    private function extractTextFromDocument(PDFDocument $pdfDocument): string
    {
        $text = '';

        // Get all pages
        try {
            $pages = $pdfDocument->getAllPages();
        } catch (Exception) {
            // No pages found or error retrieving pages
            return '';
        }

        if ($pages === []) {
            return '';
        }

        // Extract text from each page
        foreach ($pages as $page) {
            if ($page === null) {
                continue;
            }

            $pageText = $this->extractTextFromPage($page, $pdfDocument);

            if ($pageText !== '') {
                $text .= $pageText . "\n";
            }
        }

        return trim($text);
    }

    /**
     * Extract text from a specific page in a PDFDocument.
     *
     * @param PDFDocument $pdfDocument The PDF document
     * @param int         $pageNumber  Page number (1-based)
     *
     * @return string Extracted text from the specified page
     */
    private function extractTextFromDocumentPage(PDFDocument $pdfDocument, int $pageNumber): string
    {
        try {
            $pages = $pdfDocument->getAllPages();
        } catch (Exception) {
            return '';
        }

        if ($pages === [] || $pageNumber < 1 || $pageNumber > count($pages)) {
            return '';
        }

        $page = $pages[$pageNumber - 1];

        if ($page === null) {
            return '';
        }

        return trim($this->extractTextFromPage($page, $pdfDocument));
    }

    /**
     * Extract text from a range of pages in a PDFDocument.
     *
     * @param PDFDocument $pdfDocument The PDF document
     * @param int         $startPage   Start page number (1-based, inclusive)
     * @param int         $endPage     End page number (1-based, inclusive)
     *
     * @return array<int, string> Array of page numbers => extracted text
     */
    private function extractTextFromDocumentPages(PDFDocument $pdfDocument, int $startPage, int $endPage): array
    {
        $result = [];

        try {
            $pages = $pdfDocument->getAllPages();
        } catch (Exception) {
            return $result;
        }

        if ($pages === []) {
            return $result;
        }

        $totalPages = count($pages);
        $startPage  = max(1, $startPage);
        $endPage    = min($totalPages, $endPage);

        for ($pageNum = $startPage; $pageNum <= $endPage; $pageNum++) {
            $page = $pages[$pageNum - 1];

            if ($page === null) {
                $result[$pageNum] = '';

                continue;
            }

            $result[$pageNum] = trim($this->extractTextFromPage($page, $pdfDocument));
        }

        return $result;
    }

    /**
     * Extract text from a page object.
     *
     * @param mixed       $page        The page object
     * @param PDFDocument $pdfDocument The PDF document for resolving references
     *
     * @return string Extracted text
     */
    private function extractTextFromPage($page, PDFDocument $pdfDocument): string
    {
        // Get the page's Contents entry
        $pageDict = $page->getValue();

        if (!($pageDict instanceof PDFDictionary)) {
            return '';
        }

        $contentsEntry = $pageDict->getEntry('/Contents');

        if ($contentsEntry === null) {
            return '';
        }

        // Contents can be a reference, a stream, or an array of streams
        $contentStreams = [];

        // Resolve reference if needed
        if ($contentsEntry instanceof PDFReference) {
            $contentObj = $pdfDocument->getObject($contentsEntry->getObjectNumber());

            if ($contentObj instanceof PDFObjectNode) {
                $contentsEntry = $contentObj->getValue();
            }
        }

        // Handle single stream
        if ($contentsEntry instanceof PDFStream) {
            $contentStreams[] = $contentsEntry;
        }
        // Handle array of streams
        elseif ($contentsEntry instanceof PDFArray) {
            foreach ($contentsEntry->getAll() as $streamRef) {
                if ($streamRef instanceof PDFReference) {
                    $streamObj = $pdfDocument->getObject($streamRef->getObjectNumber());

                    if ($streamObj instanceof PDFObjectNode && $streamObj->getValue() instanceof PDFStream) {
                        $contentStreams[] = $streamObj->getValue();
                    }
                } elseif ($streamRef instanceof PDFStream) {
                    $contentStreams[] = $streamRef;
                }
            }
        }

        // Extract text from all content streams
        $text = '';

        foreach ($contentStreams as $contentStream) {
            $text .= $this->extractTextFromContentStream($contentStream);
        }

        return $text;
    }

    /**
     * Extract text from a content stream.
     *
     * @param PDFStream $pdfStream The content stream
     *
     * @return string Extracted text
     */
    private function extractTextFromContentStream(PDFStream $pdfStream): string
    {
        // Get decoded stream data
        $content = $pdfStream->getDecodedData();

        return $this->parseTextFromContent($content);
    }

    /**
     * Parse text from PDF content stream data.
     *
     * This extracts text from PDF text-showing operators like Tj and TJ.
     *
     * @param string $content PDF content stream
     *
     * @return string Extracted text
     */
    private function parseTextFromContent(string $content): string
    {
        $text = '';

        // Build an array of all text operators with their positions
        $textElements = [];

        // Extract text from Tj operator: (text) Tj
        if (preg_match_all('/\(((?:[^()\\\\]|\\\\.|\\\\(?:\r\n|\r|\n))*)\)\s*Tj/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $idx => $match) {
                $textElements[] = [
                    'pos'  => $match[1],
                    'text' => $this->decodeTextString($matches[1][$idx][0]),
                    'type' => 'Tj',
                ];
            }
        }

        // Extract text from TJ operator: [(text1) num (text2)] TJ
        // In TJ arrays, numbers represent kerning/spacing. Negative numbers < -100 often indicate word spacing
        if (preg_match_all('/\[((?:[^\[\]\\\\]|\\\\.|\\\\(?:\r\n|\r|\n))*)\]\s*TJ/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $idx => $match) {
                $arrayContent = $matches[1][$idx][0];
                $text_parts   = [];

                // Parse the array content: mix of strings and numbers
                preg_match_all('/\(((?:[^()\\\\]|\\\\.|\\\\(?:\r\n|\r|\n))*)\)|(-?\d+(?:\.\d+)?)/', $arrayContent, $arrayMatches, PREG_SET_ORDER);

                foreach ($arrayMatches as $element) {
                    if (isset($element[1]) && $element[1] !== '') {
                        // It's a string
                        $text_parts[] = $this->decodeTextString($element[1]);
                    } elseif (isset($element[2])) {
                        // It's a number (kerning value)
                        // Negative values < -100 often indicate word spacing
                        $num = (float) $element[2];

                        if ($num < -100) {
                            $text_parts[] = ' ';
                        }
                    }
                }

                $textElements[] = [
                    'pos'  => $match[1],
                    'text' => implode('', $text_parts),
                    'type' => 'TJ',
                ];
            }
        }

        // Extract text from ' (single quote) operator: (text) '
        if (preg_match_all('/\(((?:[^()\\\\]|\\\\.|\\\\(?:\r\n|\r|\n))*)\)\s*\'/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $idx => $match) {
                $textElements[] = [
                    'pos'  => $match[1],
                    'text' => $this->decodeTextString($matches[1][$idx][0]),
                    'type' => '\'',
                ];
            }
        }

        // Extract text from " (double quote) operator
        if (preg_match_all('/\(((?:[^()\\\\]|\\\\.|\\\\(?:\r\n|\r|\n))*)\)\s*"/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $idx => $match) {
                $textElements[] = [
                    'pos'  => $match[1],
                    'text' => $this->decodeTextString($matches[1][$idx][0]),
                    'type' => '"',
                ];
            }
        }

        // Sort by position to maintain order
        usort($textElements, static fn ($a, $b) => $a['pos'] <=> $b['pos']);

        // Look for line break operators between text elements to add proper spacing
        $previousPos = 0;

        foreach ($textElements as $element) {
            // Check for positioning operators between previous and current text
            if ($previousPos > 0) {
                $between = substr($content, $previousPos, $element['pos'] - $previousPos);

                // Look for text positioning operators that indicate new lines or spacing
                // T* = move to next line, Td/TD = move text position, Tm = set text matrix
                if (preg_match('/(T\*|Td|TD|Tm)/', $between)) {
                    $text .= "\n";
                } else {
                    // Add space between text elements if no explicit positioning
                    $text .= ' ';
                }
            }

            $text .= $element['text'];
            $previousPos = $element['pos'] + 50; // Approximate, just need a position after this element
        }

        return trim($text);
    }

    /**
     * Decode a PDF text string (handle escape sequences).
     *
     * @param string $str The encoded string
     *
     * @return string The decoded string
     */
    private function decodeTextString(string $str): string
    {
        // Handle common PDF escape sequences
        $str = str_replace('\\n', "\n", $str);
        $str = str_replace('\\r', "\r", $str);
        $str = str_replace('\\t', "\t", $str);
        $str = str_replace('\\b', "\b", $str);
        $str = str_replace('\\f', "\f", $str);
        $str = str_replace('\\(', '(', $str);
        $str = str_replace('\\)', ')', $str);
        $str = str_replace('\\\\', '\\', $str);

        // Handle octal escape sequences (\ddd)
        return preg_replace('/\\\\([0-7]{1,3})/', '', $str); // Simplified for now
    }
}
