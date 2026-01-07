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
use const STR_PAD_LEFT;
use function chr;
use function count;
use function dechex;
use function filesize;
use function hexdec;
use function implode;
use function max;
use function min;
use function ord;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function str_pad;
use function str_replace;
use function strlen;
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
        $pagePercentage = $totalPages > 0 ? ($pagesNeeded / $totalPages) : 0;

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
     * Extract text from a PDF file using a streaming approach.
     * This method processes pages one by one and calls the callback for each page,
     * allowing for memory-efficient processing of large PDFs.
     *
     * @param callable $callback  Function to call for each page: function(string $text, int $pageNumber): void
     * @param string   $filePath  Path to the PDF file
     *
     * @throws FpdfException If the PDF cannot be parsed or pages cannot be retrieved
     */
    public function extractFromFileStreaming(callable $callback, string $filePath): void
    {
        // Parse document once
        $document = $this->pdfParser->parseDocumentFromFile($filePath, $this->fileReader);

        // Get all pages
        try {
            $pages = $document->getAllPages();
        } catch (Exception) {
            return;
        }

        if ($pages === []) {
            return;
        }

        // Process each page and call the callback
        foreach ($pages as $pageIndex => $page) {
            if ($page === null) {
                continue;
            }

            $pageNumber = $pageIndex + 1;
            $pageText = $this->extractTextFromPage($page, $document);

            // Call the callback with the page text and page number
            $callback($pageText, $pageNumber);
        }
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
        $text = $this->extractTextFromDocumentPages($document, $startPage, $endPage);

        // Get file size for buffer info
        $bufferSize = filesize($filePath) ?: 0;

        return [
            'text' => $text,
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
        $startPage = max(1, $startPage);
        $endPage = min($totalPages, $endPage);

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

        // Extract font information from Resources for character mapping
        $fontMappings = $this->extractFontMappings($pageDict, $pdfDocument);

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
            $text .= $this->extractTextFromContentStream($contentStream, $fontMappings);
        }

        return $text;
    }

    /**
     * Extract text from a content stream.
     *
     * @param PDFStream $pdfStream    The content stream
     * @param array     $fontMappings Font name to ToUnicode mapping
     *
     * @return string Extracted text
     */
    private function extractTextFromContentStream(PDFStream $pdfStream, array $fontMappings = []): string
    {
        // Get decoded stream data
        $content = $pdfStream->getDecodedData();

        return $this->parseTextFromContent($content, $fontMappings);
    }

    /**
     * Parse text from PDF content stream data.
     *
     * This extracts text from PDF text-showing operators like Tj and TJ.
     *
     * @param string $content      PDF content stream
     * @param array  $fontMappings Font name to ToUnicode mapping
     *
     * @return string Extracted text
     */
    private function parseTextFromContent(string $content, array $fontMappings = []): string
    {
        $text = '';

        // Extract font selections: /FontName size Tf with positions
        $fontSelections = [];

        if (preg_match_all('/\/([A-Za-z0-9]+)\s+[\d.]+\s+Tf/', $content, $fontMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($fontMatches[0] as $idx => $match) {
                $fontSelections[] = [
                    'pos' => $match[1],
                    'font' => $fontMatches[1][$idx][0],
                ];
            }
        }

        // Helper function to get active font at a given position
        $getActiveFont = static function ($position) use ($fontSelections): ?string {
            $activeFont = null;

            foreach ($fontSelections as $selection) {
                if ($selection['pos'] < $position) {
                    $activeFont = $selection['font'];
                } else {
                    break;
                }
            }

            return $activeFont;
        };

        // Build an array of all text operators with their positions
        $textElements = [];

        // Extract text from Tj operator: (text) Tj or <hex> Tj
        if (preg_match_all('/(?:\(((?:[^()\\\\]|\\\\.|\\\\(?:\r\n|\r|\n))*)\)|<([0-9A-Fa-f]+)>)\s*Tj/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $idx => $match) {
                $currentFont = $getActiveFont($match[1]);
                $decodedText = '';

                if (isset($matches[1][$idx][0]) && $matches[1][$idx][0] !== '') {
                    // Literal string
                    $decodedText = $this->decodeTextString($matches[1][$idx][0]);
                } elseif (isset($matches[2][$idx][0]) && $matches[2][$idx][0] !== '') {
                    // Hex string - use font mapping
                    $decodedText = $this->decodeHexStringWithMapping($matches[2][$idx][0], $currentFont, $fontMappings);
                }

                $textElements[] = [
                    'pos' => $match[1],
                    'text' => $decodedText,
                    'type' => 'Tj',
                ];
            }
        }

        // Extract text from TJ operator: [(text1) num (text2)] TJ
        // In TJ arrays, numbers represent kerning/spacing. Negative numbers < -100 often indicate word spacing
        if (preg_match_all('/\[((?:[^\[\]\\\\]|\\\\.|\\\\(?:\r\n|\r|\n))*)\]\s*TJ/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $idx => $match) {
                $currentFont = $getActiveFont($match[1]);
                $arrayContent = $matches[1][$idx][0];
                $text_parts = [];

                // Parse the array content: mix of strings (literal or hex), and numbers
                // Match: (literal string) or <hex string> or number
                preg_match_all('/\(((?:[^()\\\\]|\\\\.|\\\\(?:\r\n|\r|\n))*)\)|<([0-9A-Fa-f]+)>|(-?\d+(?:\.\d+)?)/', $arrayContent, $arrayMatches, PREG_SET_ORDER);

                foreach ($arrayMatches as $element) {
                    if (isset($element[1]) && $element[1] !== '') {
                        // It's a literal string
                        $text_parts[] = $this->decodeTextString($element[1]);
                    } elseif (isset($element[2]) && $element[2] !== '') {
                        // It's a hexadecimal string - use font mapping
                        $text_parts[] = $this->decodeHexStringWithMapping($element[2], $currentFont, $fontMappings);
                    } elseif (isset($element[3])) {
                        // It's a number (kerning value)
                        // Negative values < -100 often indicate word spacing
                        $num = (float) $element[3];

                        if ($num < -100) {
                            $text_parts[] = ' ';
                        }
                    }
                }

                $textElements[] = [
                    'pos' => $match[1],
                    'text' => implode('', $text_parts),
                    'type' => 'TJ',
                ];
            }
        }

        // Extract text from ' (single quote) operator: (text) ' or <hex> '
        if (preg_match_all('/(?:\(((?:[^()\\\\]|\\\\.|\\\\(?:\r\n|\r|\n))*)\)|<([0-9A-Fa-f]+)>)\s*\'/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $idx => $match) {
                $currentFont = $getActiveFont($match[1]);
                $decodedText = '';

                if (isset($matches[1][$idx][0]) && $matches[1][$idx][0] !== '') {
                    // Literal string
                    $decodedText = $this->decodeTextString($matches[1][$idx][0]);
                } elseif (isset($matches[2][$idx][0]) && $matches[2][$idx][0] !== '') {
                    // Hex string - use font mapping
                    $decodedText = $this->decodeHexStringWithMapping($matches[2][$idx][0], $currentFont, $fontMappings);
                }

                $textElements[] = [
                    'pos' => $match[1],
                    'text' => $decodedText,
                    'type' => '\'',
                ];
            }
        }

        // Extract text from " (double quote) operator: (text) " or <hex> "
        if (preg_match_all('/(?:\(((?:[^()\\\\]|\\\\.|\\\\(?:\r\n|\r|\n))*)\)|<([0-9A-Fa-f]+)>)\s*"/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $idx => $match) {
                $currentFont = $getActiveFont($match[1]);
                $decodedText = '';

                if (isset($matches[1][$idx][0]) && $matches[1][$idx][0] !== '') {
                    // Literal string
                    $decodedText = $this->decodeTextString($matches[1][$idx][0]);
                } elseif (isset($matches[2][$idx][0]) && $matches[2][$idx][0] !== '') {
                    // Hex string - use font mapping
                    $decodedText = $this->decodeHexStringWithMapping($matches[2][$idx][0], $currentFont, $fontMappings);
                }

                $textElements[] = [
                    'pos' => $match[1],
                    'text' => $decodedText,
                    'type' => '"',
                ];
            }
        }

        // Sort by position to maintain order
        usort($textElements, static fn($a, $b) => $a['pos'] <=> $b['pos']);

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

    /**
     * Decode a PDF hexadecimal string using font mapping (ToUnicode CMap) if available.
     *
     * @param string      $hexStr       The hex string (without < > delimiters)
     * @param null|string $currentFont  The current font name
     * @param array       $fontMappings Font name => character mapping
     *
     * @return string The decoded string
     */
    private function decodeHexStringWithMapping(string $hexStr, ?string $currentFont, array $fontMappings): string
    {
        // Remove any whitespace
        $hexStr = preg_replace('/\s+/', '', $hexStr);

        // If odd length, pad with 0
        if (strlen($hexStr) % 2 !== 0) {
            $hexStr .= '0';
        }

        // Check if we have a font mapping for the current font
        if ($currentFont !== null && isset($fontMappings[$currentFont])) {
            $mapping = $fontMappings[$currentFont];
            $result = '';

            // Decode using font mapping (usually 2-byte or 4-byte character codes)
            // Try 4-byte codes first (common for CID fonts), then 2-byte
            for ($i = 0; $i < strlen($hexStr); ) {
                $matched = false;

                // Try 4-byte (8 hex digits)
                if ($i + 8 <= strlen($hexStr)) {
                    $code4 = hexdec(substr($hexStr, $i, 8));

                    if (isset($mapping[$code4])) {
                        $result .= $mapping[$code4];
                        $i += 8;
                        $matched = true;
                    }
                }

                // Try 2-byte (4 hex digits)
                if (!$matched && $i + 4 <= strlen($hexStr)) {
                    $code2 = hexdec(substr($hexStr, $i, 4));

                    if (isset($mapping[$code2])) {
                        $result .= $mapping[$code2];
                        $i += 4;
                        $matched = true;
                    }
                }

                // Try 1-byte (2 hex digits)
                if (!$matched && $i + 2 <= strlen($hexStr)) {
                    $code1 = hexdec(substr($hexStr, $i, 2));

                    if (isset($mapping[$code1])) {
                        $result .= $mapping[$code1];
                        $i += 2;
                        $matched = true;
                    }
                }

                // If no mapping found, skip this byte
                if (!$matched) {
                    $i += 2;
                }
            }

            return $result;
        }

        // Fall back to original hex decoding if no mapping available
        return $this->decodeHexString($hexStr);
    }

    /**
     * Decode a PDF hexadecimal string.
     * Hex strings in PDFs are encoded as <hexdigits> where each pair represents a byte.
     * The encoding can be various: ASCII, UTF-16BE (with BOM FEFF), or custom encoding.
     *
     * @param string $hexStr The hex string (without < > delimiters)
     *
     * @return string The decoded string
     */
    private function decodeHexString(string $hexStr): string
    {
        // Remove any whitespace (PDFs allow whitespace in hex strings)
        $hexStr = preg_replace('/\s+/', '', $hexStr);

        // If odd length, pad with 0
        if (strlen($hexStr) % 2 !== 0) {
            $hexStr .= '0';
        }

        // Convert hex to binary
        $binary = '';

        for ($i = 0; $i < strlen($hexStr); $i += 2) {
            $binary .= chr((int) hexdec(substr($hexStr, $i, 2)));
        }

        // Check for UTF-16BE BOM (FEFF)
        if (strlen($binary) >= 2 && substr($binary, 0, 2) === "\xFE\xFF") {
            // UTF-16BE encoding
            $binary = substr($binary, 2); // Remove BOM

            // Convert UTF-16BE to UTF-8
            $utf8 = '';

            for ($i = 0; $i < strlen($binary); $i += 2) {
                if ($i + 1 < strlen($binary)) {
                    $codepoint = (ord($binary[$i]) << 8) | ord($binary[$i + 1]);

                    // Simple conversion (handles BMP only, not surrogates)
                    if ($codepoint < 0x80) {
                        $utf8 .= chr($codepoint);
                    } elseif ($codepoint < 0x800) {
                        $utf8 .= chr(0xC0 | ($codepoint >> 6));
                        $utf8 .= chr(0x80 | ($codepoint & 0x3F));
                    } else {
                        $utf8 .= chr(0xE0 | ($codepoint >> 12));
                        $utf8 .= chr(0x80 | (($codepoint >> 6) & 0x3F));
                        $utf8 .= chr(0x80 | ($codepoint & 0x3F));
                    }
                }
            }

            return $utf8;
        }

        // Check if it looks like a glyph ID (small numbers, typically < 256 per character)
        // Many PDFs use custom encodings where hex values map to glyphs via font encoding
        // For now, treat as-is, but ideally we'd look up the font encoding

        // If all bytes are in printable ASCII range or look reasonable, return as-is
        // This handles simple ASCII encodings and single-byte encodings
        return $binary;
    }

    /**
     * Extract font mappings (ToUnicode CMaps) from page resources.
     *
     * @param PDFDictionary $pageDict    The page dictionary
     * @param PDFDocument   $pdfDocument The PDF document
     *
     * @return array<string, array> Font name => character mapping array
     */
    private function extractFontMappings(PDFDictionary $pageDict, PDFDocument $pdfDocument): array
    {
        $fontMappings = [];

        // Get Resources dictionary
        $resourcesEntry = $pageDict->getEntry('/Resources');

        if ($resourcesEntry === null) {
            return $fontMappings;
        }

        // Resolve reference if needed
        if ($resourcesEntry instanceof PDFReference) {
            $resourcesObj = $pdfDocument->getObject($resourcesEntry->getObjectNumber());

            if ($resourcesObj instanceof PDFObjectNode) {
                $resourcesEntry = $resourcesObj->getValue();
            }
        }

        if (!($resourcesEntry instanceof PDFDictionary)) {
            return $fontMappings;
        }

        // Get Font dictionary
        $fontEntry = $resourcesEntry->getEntry('/Font');

        if ($fontEntry === null) {
            return $fontMappings;
        }

        // Resolve reference if needed
        if ($fontEntry instanceof PDFReference) {
            $fontObj = $pdfDocument->getObject($fontEntry->getObjectNumber());

            if ($fontObj instanceof PDFObjectNode) {
                $fontEntry = $fontObj->getValue();
            }
        }

        if (!($fontEntry instanceof PDFDictionary)) {
            return $fontMappings;
        }

        // Iterate through each font
        foreach ($fontEntry->getAllEntries() as $fontName => $fontRef) {
            if ($fontRef instanceof PDFReference) {
                $fontObj = $pdfDocument->getObject($fontRef->getObjectNumber());

                if ($fontObj instanceof PDFObjectNode) {
                    $fontDict = $fontObj->getValue();

                    if ($fontDict instanceof PDFDictionary) {
                        // Extract ToUnicode CMap
                        $toUnicodeMapping = $this->extractToUnicodeCMap($fontDict, $pdfDocument);

                        if ($toUnicodeMapping !== []) {
                            $fontMappings[$fontName] = $toUnicodeMapping;
                        }
                    }
                }
            }
        }

        return $fontMappings;
    }

    /**
     * Extract ToUnicode CMap from font dictionary.
     *
     * @param PDFDictionary $fontDict    The font dictionary
     * @param PDFDocument   $pdfDocument The PDF document
     *
     * @return array<int, string> Character code => Unicode character mapping
     */
    private function extractToUnicodeCMap(PDFDictionary $fontDict, PDFDocument $pdfDocument): array
    {
        $mapping = [];

        // Get ToUnicode entry
        $toUnicodeEntry = $fontDict->getEntry('/ToUnicode');

        if ($toUnicodeEntry === null) {
            return $mapping;
        }

        // Resolve reference
        if ($toUnicodeEntry instanceof PDFReference) {
            $toUnicodeObj = $pdfDocument->getObject($toUnicodeEntry->getObjectNumber());

            if ($toUnicodeObj instanceof PDFObjectNode) {
                $toUnicodeEntry = $toUnicodeObj->getValue();
            }
        }

        if (!($toUnicodeEntry instanceof PDFStream)) {
            return $mapping;
        }

        // Parse CMap data
        $cmapData = $toUnicodeEntry->getDecodedData();

        return $this->parseToUnicodeCMap($cmapData);
    }

    /**
     * Parse ToUnicode CMap data to extract character mappings.
     *
     * @param string $cmapData The CMap stream data
     *
     * @return array<int, string> Character code => Unicode character mapping
     */
    private function parseToUnicodeCMap(string $cmapData): array
    {
        $mapping = [];

        // Parse bfchar sections: <srcCode> <dstUnicode>
        if (preg_match_all('/beginbfchar\s+(.*?)\s+endbfchar/s', $cmapData, $bfcharMatches)) {
            foreach ($bfcharMatches[1] as $bfcharSection) {
                // Match pairs of hex strings: <XX> <YYYY>
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $bfcharSection, $pairs)) {
                    for ($i = 0; $i < count($pairs[1]); $i++) {
                        $srcCode = hexdec($pairs[1][$i]);
                        $dstCode = $pairs[2][$i];

                        // Convert destination hex to UTF-8
                        $unicodeChar = $this->hexToUTF8($dstCode);
                        $mapping[$srcCode] = $unicodeChar;
                    }
                }
            }
        }

        // Parse bfrange sections: <startCode> <endCode> <dstUnicode>
        if (preg_match_all('/beginbfrange\s+(.*?)\s+endbfrange/s', $cmapData, $bfrangeMatches)) {
            foreach ($bfrangeMatches[1] as $bfrangeSection) {
                // Match: <start> <end> <baseUnicode> or <start> <end> [<unicode1> <unicode2> ...]
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(?:<([0-9A-Fa-f]+)>|\[(.*?)\])/s', $bfrangeSection, $ranges, PREG_SET_ORDER)) {
                    foreach ($ranges as $range) {
                        $start = hexdec($range[1]);
                        $end = hexdec($range[2]);

                        if (isset($range[3]) && $range[3] !== '') {
                            // Single base code, increment for range
                            $baseCode = $range[3];
                            $baseNum = hexdec($baseCode);

                            for ($code = $start; $code <= $end; $code++) {
                                $unicodeValue = $baseNum + ($code - $start);
                                $hexValue = str_pad(dechex($unicodeValue), strlen($baseCode), '0', STR_PAD_LEFT);
                                $mapping[$code] = $this->hexToUTF8($hexValue);
                            }
                        } elseif (isset($range[4])) {
                            // Array of codes
                            if (preg_match_all('/<([0-9A-Fa-f]+)>/', $range[4], $arrayMatches)) {
                                $code = $start;

                                foreach ($arrayMatches[1] as $hexCode) {
                                    if ($code <= $end) {
                                        $mapping[$code] = $this->hexToUTF8($hexCode);
                                        $code++;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return $mapping;
    }

    /**
     * Convert hex string to UTF-8 character(s).
     *
     * @param string $hexStr Hex string (without delimiters)
     *
     * @return string UTF-8 character(s)
     */
    private function hexToUTF8(string $hexStr): string
    {
        // Pad to even length
        if (strlen($hexStr) % 2 !== 0) {
            $hexStr = '0' . $hexStr;
        }

        $result = '';

        // Process as UTF-16BE pairs
        for ($i = 0; $i < strlen($hexStr); $i += 4) {
            $hexPair = substr($hexStr, $i, 4);

            if (strlen($hexPair) < 4) {
                // Single byte, treat as-is
                $hexPair = substr($hexStr, $i, 2);
                $codepoint = hexdec($hexPair);
            } else {
                // Two bytes (UTF-16 code unit)
                $codepoint = hexdec($hexPair);
            }

            // Convert codepoint to UTF-8
            if ($codepoint < 0x80) {
                $result .= chr($codepoint);
            } elseif ($codepoint < 0x800) {
                $result .= chr(0xC0 | ($codepoint >> 6));
                $result .= chr(0x80 | ($codepoint & 0x3F));
            } elseif ($codepoint < 0x10000) {
                $result .= chr(0xE0 | ($codepoint >> 12));
                $result .= chr(0x80 | (($codepoint >> 6) & 0x3F));
                $result .= chr(0x80 | ($codepoint & 0x3F));
            } else {
                // Handle surrogates or 4-byte UTF-8 (simplified)
                $result .= chr(0xF0 | ($codepoint >> 18));
                $result .= chr(0x80 | (($codepoint >> 12) & 0x3F));
                $result .= chr(0x80 | (($codepoint >> 6) & 0x3F));
                $result .= chr(0x80 | ($codepoint & 0x3F));
            }
        }

        return $result;
    }
}
