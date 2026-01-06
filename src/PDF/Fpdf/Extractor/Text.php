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
namespace PXP\PDF\Fpdf\Extractor;

use function count;
use function implode;
use function max;
use function min;
use function preg_match_all;
use function preg_replace;
use function str_replace;
use function trim;
use Exception;
use PXP\PDF\Fpdf\IO\FileIO;
use PXP\PDF\Fpdf\IO\FileReaderInterface;
use PXP\PDF\Fpdf\Object\Base\PDFArray;
use PXP\PDF\Fpdf\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Object\Parser\PDFParser;
use PXP\PDF\Fpdf\Stream\PDFStream;
use PXP\PDF\Fpdf\Tree\PDFDocument;

/**
 * Extracts text content from PDF documents.
 */
class Text
{
    private ?FileReaderInterface $fileReader = null;
    private ?PDFParser $parser               = null;

    public function __construct(
        ?FileReaderInterface $fileReader = null,
        ?PDFParser $parser = null,
    ) {
        $this->fileReader = $fileReader ?? new FileIO;
        $this->parser     = $parser ?? new PDFParser;
    }

    /**
     * Extract text from a PDF file.
     *
     * @param string $filePath Path to the PDF file
     *
     * @return string Extracted text
     */
    public function extractFromFile(string $filePath): string
    {
        $content = $this->fileReader->readFile($filePath);

        return $this->extractFromString($content);
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
        $document = $this->parser->parseDocument($pdfContent);

        return $this->extractTextFromDocument($document);
    }

    /**
     * Extract text from a specific page of a PDF file.
     *
     * @param string $filePath   Path to the PDF file
     * @param int    $pageNumber Page number (1-based)
     *
     * @return string Extracted text from the specified page
     */
    public function extractFromFilePage(string $filePath, int $pageNumber): string
    {
        $content  = $this->fileReader->readFile($filePath);
        $document = $this->parser->parseDocument($content);

        return $this->extractTextFromDocumentPage($document, $pageNumber);
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
        $document = $this->parser->parseDocument($pdfContent);

        return $this->extractTextFromDocumentPage($document, $pageNumber);
    }

    /**
     * Extract text from a range of pages in a PDF file.
     *
     * @param string $filePath  Path to the PDF file
     * @param int    $startPage Start page number (1-based, inclusive)
     * @param int    $endPage   End page number (1-based, inclusive)
     *
     * @return array<int, string> Array of page numbers => extracted text
     */
    public function extractFromFilePages(string $filePath, int $startPage, int $endPage): array
    {
        $content  = $this->fileReader->readFile($filePath);
        $document = $this->parser->parseDocument($content);

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
        $document = $this->parser->parseDocument($pdfContent);

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
        $content  = $this->fileReader->readFile($filePath);
        $document = $this->parser->parseDocument($content);

        try {
            $pages = $document->getAllPages();

            return count($pages);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Extract text from a PDFDocument object.
     *
     * @param PDFDocument $document The PDF document
     *
     * @return string Extracted text
     */
    private function extractTextFromDocument(PDFDocument $document): string
    {
        $text = '';

        // Get all pages
        try {
            $pages = $document->getAllPages();
        } catch (Exception $e) {
            // No pages found or error retrieving pages
            return '';
        }

        if (empty($pages)) {
            return '';
        }

        // Extract text from each page
        foreach ($pages as $page) {
            if ($page === null) {
                continue;
            }

            $pageText = $this->extractTextFromPage($page, $document);

            if ($pageText !== '') {
                $text .= $pageText . "\n";
            }
        }

        return trim($text);
    }

    /**
     * Extract text from a specific page in a PDFDocument.
     *
     * @param PDFDocument $document   The PDF document
     * @param int         $pageNumber Page number (1-based)
     *
     * @return string Extracted text from the specified page
     */
    private function extractTextFromDocumentPage(PDFDocument $document, int $pageNumber): string
    {
        try {
            $pages = $document->getAllPages();
        } catch (Exception $e) {
            return '';
        }

        if (empty($pages) || $pageNumber < 1 || $pageNumber > count($pages)) {
            return '';
        }

        $page = $pages[$pageNumber - 1];

        if ($page === null) {
            return '';
        }

        return trim($this->extractTextFromPage($page, $document));
    }

    /**
     * Extract text from a range of pages in a PDFDocument.
     *
     * @param PDFDocument $document  The PDF document
     * @param int         $startPage Start page number (1-based, inclusive)
     * @param int         $endPage   End page number (1-based, inclusive)
     *
     * @return array<int, string> Array of page numbers => extracted text
     */
    private function extractTextFromDocumentPages(PDFDocument $document, int $startPage, int $endPage): array
    {
        $result = [];

        try {
            $pages = $document->getAllPages();
        } catch (Exception $e) {
            return $result;
        }

        if (empty($pages)) {
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

            $result[$pageNum] = trim($this->extractTextFromPage($page, $document));
        }

        return $result;
    }

    /**
     * Extract text from a page object.
     *
     * @param mixed       $page     The page object
     * @param PDFDocument $document The PDF document for resolving references
     *
     * @return string Extracted text
     */
    private function extractTextFromPage($page, PDFDocument $document): string
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
            $contentObj = $document->getObject($contentsEntry->getObjectNumber());

            if ($contentObj !== null) {
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
                    $streamObj = $document->getObject($streamRef->getObjectNumber());

                    if ($streamObj !== null && $streamObj->getValue() instanceof PDFStream) {
                        $contentStreams[] = $streamObj->getValue();
                    }
                } elseif ($streamRef instanceof PDFStream) {
                    $contentStreams[] = $streamRef;
                }
            }
        }

        // Extract text from all content streams
        $text = '';

        foreach ($contentStreams as $stream) {
            $text .= $this->extractTextFromContentStream($stream);
        }

        return $text;
    }

    /**
     * Extract text from a content stream.
     *
     * @param PDFStream $stream The content stream
     *
     * @return string Extracted text
     */
    private function extractTextFromContentStream(PDFStream $stream): string
    {
        // Get decoded stream data
        $content = $stream->getDecodedData();

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

        // Extract text from Tj operator: (text) Tj
        // Match: (text content) Tj
        $matches = [];

        if (preg_match_all('/\(((?:[^()\\\\]|\\\\.|\\\\(?:\r\n|\r|\n))*)\)\s*Tj/', $content, $matches)) {
            foreach ($matches[1] as $match) {
                $text .= $this->decodeTextString($match) . ' ';
            }
        }

        // Extract text from TJ operator: [(text1) num (text2)] TJ
        // Match arrays like: [(Hello) -250 (World)]
        if (preg_match_all('/\[((?:[^\[\]\\\\]|\\\\.|\\\\(?:\r\n|\r|\n))*)\]\s*TJ/', $content, $matches)) {
            foreach ($matches[1] as $match) {
                // Extract strings from the array
                $strings = [];

                if (preg_match_all('/\(((?:[^()\\\\]|\\\\.|\\\\(?:\r\n|\r|\n))*)\)/', $match, $stringMatches)) {
                    foreach ($stringMatches[1] as $str) {
                        $strings[] = $this->decodeTextString($str);
                    }
                }
                $text .= implode('', $strings) . ' ';
            }
        }

        // Extract text from ' (single quote) operator: (text) '
        if (preg_match_all('/\(((?:[^()\\\\]|\\\\.|\\\\(?:\r\n|\r|\n))*)\)\s*\'/', $content, $matches)) {
            foreach ($matches[1] as $match) {
                $text .= $this->decodeTextString($match) . ' ';
            }
        }

        // Extract text from " (double quote) operator
        if (preg_match_all('/\(((?:[^()\\\\]|\\\\.|\\\\(?:\r\n|\r|\n))*)\)\s*"/', $content, $matches)) {
            foreach ($matches[1] as $match) {
                $text .= $this->decodeTextString($match) . ' ';
            }
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
