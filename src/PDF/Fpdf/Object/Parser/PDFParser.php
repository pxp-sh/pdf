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

namespace PXP\PDF\Fpdf\Object\Parser;

use PXP\PDF\Fpdf\Cache\NullCache;
use PXP\PDF\Fpdf\Exception\FpdfException;
use PXP\PDF\Fpdf\IO\FileIOInterface;
use PXP\PDF\Fpdf\Log\NullLogger;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use PXP\PDF\Fpdf\Object\Array\KidsArray;
use PXP\PDF\Fpdf\Object\Array\MediaBoxArray;
use PXP\PDF\Fpdf\Object\Base\PDFArray;
use PXP\PDF\Fpdf\Object\Base\PDFBoolean;
use PXP\PDF\Fpdf\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Object\Base\PDFName;
use PXP\PDF\Fpdf\Object\Base\PDFNull;
use PXP\PDF\Fpdf\Object\Base\PDFNumber;
use PXP\PDF\Fpdf\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Object\Base\PDFString;
use PXP\PDF\Fpdf\Object\PDFObjectInterface;
use PXP\PDF\Fpdf\Stream\PDFStream;
use PXP\PDF\Fpdf\Tree\PDFDocument;
use PXP\PDF\Fpdf\Tree\PDFHeader;
use PXP\PDF\Fpdf\Tree\PDFObjectNode;
use PXP\PDF\Fpdf\Xref\PDFXrefTable;

/**
 * Main parser for reading PDFs into tree structure.
 */
final class PDFParser
{
    public function __construct(
        ?LoggerInterface $logger = null,
        ?CacheItemPoolInterface $cache = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->cache = $cache ?? new NullCache();
    }

    private LoggerInterface $logger;
    private CacheItemPoolInterface $cache;

    /**
     * Parse a full PDF document from file path (file-based lazy loading).
     * Only reads header, xref table, and trailer - objects are loaded on-demand.
     *
     * @param string $filePath Path to PDF file
     * @param FileIOInterface $fileIO File IO interface for reading
     * @return PDFDocument Parsed document with file-based lazy loading
     */
    public function parseDocumentFromFile(string $filePath, FileIOInterface $fileIO): PDFDocument
    {
        $absolutePath = realpath($filePath) ?: $filePath;
        $startTime = microtime(true);

        $this->logger->debug('PDF file parsing started', [
            'file_path' => $absolutePath,
            'file_size' => filesize($filePath),
        ]);

        $fileSize = filesize($filePath);

        // Read only header (first 8KB should be enough)
        $headerChunk = $fileIO->readFileChunk($filePath, min(8192, $fileSize), 0);
        $header = PDFHeader::parse($headerChunk);
        $version = $header !== null ? $header->getVersion() : '1.3';

        $this->logger->debug('PDF header parsed', [
            'file_path' => $absolutePath,
            'version' => $version,
        ]);

        // Find xref table by searching from the end of file
        // PDFs have xref near the end, so search backwards
        $this->logger->debug('Searching for xref table', [
            'file_path' => $absolutePath,
        ]);
        $xrefPos = $this->findXrefTableInFile($filePath, $fileIO, $fileSize);
        if ($xrefPos === false) {
            $this->logger->error('Xref table not found', [
                'file_path' => $absolutePath,
            ]);
            throw new FpdfException('Invalid PDF: xref table not found');
        }

        $this->logger->debug('Xref table found', [
            'file_path' => $absolutePath,
            'xref_position' => $xrefPos,
        ]);

        // Read xref table and trailer (read enough to get both)
        $readSize = min(131072, $fileSize - $xrefPos); // Read up to 128KB from xref
        $xrefAndTrailer = $fileIO->readFileChunk($filePath, $readSize, $xrefPos);

        $xrefEnd = strpos($xrefAndTrailer, 'trailer');
        if ($xrefEnd === false) {
            // If trailer not found in chunk, read more
            $readSize = $fileSize - $xrefPos;
            $xrefAndTrailer = $fileIO->readFileChunk($filePath, $readSize, $xrefPos);
            $xrefEnd = strpos($xrefAndTrailer, 'trailer');
            if ($xrefEnd === false) {
                $this->logger->error('Trailer not found', [
                    'file_path' => $absolutePath,
                ]);
                throw new FpdfException('Invalid PDF: trailer not found');
            }
        }

        $xrefContent = substr($xrefAndTrailer, 4, $xrefEnd - 4);
        $xrefTable = new PDFXrefTable();
        $xrefTable->parseFromString($xrefContent);

        $xrefEntryCount = count($xrefTable->getAllEntries());
        $this->logger->debug('Xref table parsed', [
            'file_path' => $absolutePath,
            'entry_count' => $xrefEntryCount,
        ]);

        // Create registry with file-based lazy loading context
        $registry = new \PXP\PDF\Fpdf\Tree\PDFObjectRegistry(null, $this, $xrefTable, $filePath, $fileIO, $this->cache, $this->logger);

        // Create document with lazy-loading enabled registry
        $document = new PDFDocument($version, $registry);

        // Set header version
        if ($header !== null) {
            $document->getHeader()->setVersion($header->getVersion());
        }

        // Parse trailer
        $trailerSection = substr($xrefAndTrailer, $xrefEnd);
        $this->parseTrailer($trailerSection, $document);

        $this->logger->debug('Trailer parsed', [
            'file_path' => $absolutePath,
            'root_object' => $document->getTrailer()->getRoot()?->getObjectNumber(),
            'info_object' => $document->getTrailer()->getInfo()?->getObjectNumber(),
        ]);

        // Only parse root object immediately (needed for document structure)
        $rootRef = $document->getTrailer()->getRoot();
        if ($rootRef !== null) {
            $this->logger->debug('Loading root object', [
                'file_path' => $absolutePath,
                'object_number' => $rootRef->getObjectNumber(),
            ]);
            $rootNode = $registry->get($rootRef->getObjectNumber());
            if ($rootNode !== null) {
                $document->setRoot($rootNode);
            }
        }

        $duration = (microtime(true) - $startTime) * 1000;
        $this->logger->info('PDF file parsing completed', [
            'file_path' => $absolutePath,
            'duration_ms' => round($duration, 2),
            'version' => $version,
            'object_count' => $xrefEntryCount,
        ]);

        return $document;
    }

    /**
     * Find xref table position in file by searching from the end.
     *
     * @param string $filePath PDF file path
     * @param FileIOInterface $fileIO File IO interface
     * @param int $fileSize File size in bytes
     * @return int|false Xref position or false if not found
     */
    private function findXrefTableInFile(string $filePath, FileIOInterface $fileIO, int $fileSize): int|false
    {
        $absolutePath = realpath($filePath) ?: $filePath;
        // Search backwards from end of file
        // Read chunks from the end and search for "xref"
        $chunkSize = 65536; // 64KB chunks
        $searchStart = max(0, $fileSize - $chunkSize);
        $chunkCount = 0;
        $previousSearchStart = $searchStart + 1; // Initialize to ensure first iteration is different
        $maxIterations = 1000; // Safety limit to prevent infinite loops

        while ($searchStart >= 0 && $chunkCount < $maxIterations) {
            $chunkCount++;
            $readSize = min($chunkSize, $fileSize - $searchStart);
            $chunk = $fileIO->readFileChunk($filePath, $readSize, $searchStart);

            // Search for "xref" in this chunk - must be standalone (not part of "startxref")
            // First try: search for "\nxref\n" or "\rxref\n" (xref on its own line)
            $pos = false;
            $patterns = ["\nxref\n", "\rxref\n", "\nxref\r\n", "\rxref\r"];
            foreach ($patterns as $pattern) {
                $foundPos = strrpos($chunk, $pattern);
                if ($foundPos !== false) {
                    $afterXref = substr($chunk, $foundPos + strlen($pattern), 20);
                    if (preg_match('/^\s*\d+\s+\d+\s*\r?\n/', $afterXref)) {
                        $pos = $foundPos + 1; // +1 to point to 'x' not '\n' or '\r'
                        break;
                    }
                }
            }

            // Fallback: search for "xref" and verify it's not part of "startxref"
            if ($pos === false) {
                $chunkLen = strlen($chunk);
                $searchEnd = $chunkLen; // Start searching from the end

                while ($searchEnd > 0) {
                    // Search in the substring up to searchEnd
                    $searchChunk = substr($chunk, 0, $searchEnd);
                    $foundPos = strrpos($searchChunk, 'xref');

                    if ($foundPos === false) {
                        // No more matches
                        break;
                    }

                    // Check if it's not part of "startxref"
                    $beforeXref = $foundPos > 0 ? substr($chunk, max(0, $foundPos - 5), 5) : '';
                    if ($beforeXref !== 'start') {
                        $afterXref = substr($chunk, $foundPos + 4, 20);
                        // Verify it's a valid xref table (should be followed by whitespace/newline and numbers)
                        if (preg_match('/^\s*\r?\n\s*\d+\s+\d+\s*\r?\n/', $afterXref)) {
                            $pos = $foundPos;
                            break;
                        }
                    }

                    // Continue searching backwards from before this position
                    $searchEnd = $foundPos;
                }
            }

            if ($pos !== false) {
                $this->logger->debug('Xref table found in chunk', [
                    'file_path' => $absolutePath,
                    'chunk_number' => $chunkCount,
                    'position' => $searchStart + $pos,
                ]);
                return $searchStart + $pos;
            }

            // Don't search too far back (xref should be in last 1MB)
            if ($fileSize - $searchStart > 1048576) {
                $this->logger->debug('Xref search limit reached', [
                    'file_path' => $absolutePath,
                    'chunks_searched' => $chunkCount,
                ]);
                break;
            }

            // Move search window backwards
            $newSearchStart = max(0, $searchStart - $chunkSize + 100); // Overlap by 100 bytes to catch xref at boundary

            // Break if we're not making progress (stuck at position 0 or same position)
            if ($newSearchStart >= $searchStart) {
                $this->logger->debug('Xref search stopped making progress', [
                    'file_path' => $absolutePath,
                    'chunks_searched' => $chunkCount,
                    'final_position' => $searchStart,
                ]);
                break;
            }

            $previousSearchStart = $searchStart;
            $searchStart = $newSearchStart;
        }

        if ($chunkCount >= $maxIterations) {
            $this->logger->warning('Xref search hit maximum iteration limit', [
                'file_path' => $absolutePath,
                'chunks_searched' => $chunkCount,
            ]);
        }

        return false;
    }

    /**
     * Parse a full PDF document from string content.
     * Uses memory-based lazy loading.
     */
    public function parseDocument(string $content): PDFDocument
    {
        $startTime = microtime(true);
        $contentLength = strlen($content);

        $this->logger->debug('PDF document parsing started (memory-based)', [
            'content_length' => $contentLength,
        ]);

        // Parse header first to get version
        $header = PDFHeader::parse($content);
        $version = $header !== null ? $header->getVersion() : '1.3';

        $this->logger->debug('PDF header parsed', [
            'version' => $version,
        ]);

        // Parse xref table
        $xrefPos = $this->findXrefTable($content);
        if ($xrefPos === false) {
            $this->logger->error('Xref table not found in content');
            throw new FpdfException('Invalid PDF: xref table not found');
        }

        $this->logger->debug('Xref table found', [
            'xref_position' => $xrefPos,
        ]);

        $xrefEnd = strpos($content, 'trailer', $xrefPos);
        if ($xrefEnd === false) {
            $this->logger->error('Trailer not found in content');
            throw new FpdfException('Invalid PDF: trailer not found');
        }

        $xrefContent = substr($content, $xrefPos + 4, $xrefEnd - $xrefPos - 4);
        $xrefTable = new \PXP\PDF\Fpdf\Xref\PDFXrefTable();
        $xrefTable->parseFromString($xrefContent);

        $xrefEntryCount = count($xrefTable->getAllEntries());
        $this->logger->debug('Xref table parsed', [
            'entry_count' => $xrefEntryCount,
        ]);

        // Create registry with native lazy loading context
        $registry = new \PXP\PDF\Fpdf\Tree\PDFObjectRegistry($content, $this, $xrefTable, null, null, $this->cache, $this->logger);

        // Create document with lazy-loading enabled registry
        $document = new PDFDocument($version, $registry);

        // Set header version
        if ($header !== null) {
            $document->getHeader()->setVersion($header->getVersion());
        }

        // Note: We don't copy xref entries to document's xref table here.
        // The registry has the full xref table for lazy loading.
        // The document's xref table will be rebuilt during serialization if needed.
        // Copying all entries (potentially 20,000+ for large PDFs) causes memory issues.

        // Parse trailer
        $trailerSection = substr($content, $xrefEnd);
        $this->parseTrailer($trailerSection, $document);

        $this->logger->debug('Trailer parsed', [
            'root_object' => $document->getTrailer()->getRoot()?->getObjectNumber(),
            'info_object' => $document->getTrailer()->getInfo()?->getObjectNumber(),
        ]);

        // Only parse root object immediately (needed for document structure)
        $rootRef = $document->getTrailer()->getRoot();
        if ($rootRef !== null) {
            $this->logger->debug('Loading root object', [
                'object_number' => $rootRef->getObjectNumber(),
            ]);
            $rootNode = $registry->get($rootRef->getObjectNumber());
            if ($rootNode !== null) {
                $document->setRoot($rootNode);
            }
        }

        $duration = (microtime(true) - $startTime) * 1000;
        $this->logger->info('PDF document parsing completed (memory-based)', [
            'duration_ms' => round($duration, 2),
            'version' => $version,
            'object_count' => $xrefEntryCount,
        ]);

        return $document;
    }

    /**
     * Find xref table position.
     */
    private function findXrefTable(string $content): int|false
    {
        $pos = 0;
        while (($pos = strpos($content, 'xref', $pos)) !== false) {
            $afterXref = substr($content, $pos + 4, 20);
            if (preg_match('/^\s*\r?\n\s*\d+\s+\d+\s*\r?\n/', $afterXref)) {
                return $pos;
            }
            $pos += 4;
        }

        return false;
    }

    /**
     * Parse trailer section.
     */
    private function parseTrailer(string $trailerSection, PDFDocument $document): void
    {
        $rootMatch = [];
        if (preg_match('/\/Root\s+(\d+)\s+(\d+)\s+R/', $trailerSection, $rootMatch)) {
            $document->getTrailer()->setRoot(new PDFReference((int) $rootMatch[1], (int) $rootMatch[2]));
        }

        $infoMatch = [];
        if (preg_match('/\/Info\s+(\d+)\s+(\d+)\s+R/', $trailerSection, $infoMatch)) {
            $document->getTrailer()->setInfo(new PDFReference((int) $infoMatch[1], (int) $infoMatch[2]));
        }

        $sizeMatch = [];
        if (preg_match('/\/Size\s+(\d+)/', $trailerSection, $sizeMatch)) {
            $document->getTrailer()->setSize((int) $sizeMatch[1]);
        }
    }

    /**
     * Extract object content from PDF.
     */
    public function extractObjectContent(string $content, int $offset, int $objectNumber, int $generation): ?string
    {
        $objStart = strpos($content, (string) $objectNumber . ' ' . $generation . ' obj', $offset);
        if ($objStart === false) {
            return null;
        }

        $objEnd = strpos($content, 'endobj', $objStart);
        if ($objEnd === false) {
            return null;
        }

        return substr($content, $objStart, $objEnd - $objStart + 6);
    }

    /**
     * Parse a single PDF object.
     */
    public function parseObject(string $objectContent, int $objectNumber): PDFObjectInterface
    {
        // Extract object body (between "obj" and "endobj")
        $objPos = strpos($objectContent, 'obj');
        if ($objPos === false) {
            throw new FpdfException('Invalid object format');
        }

        $bodyStart = $objPos + 3;
        $bodyEnd = strrpos($objectContent, 'endobj');
        if ($bodyEnd === false) {
            throw new FpdfException('Invalid object format: endobj not found');
        }

        $body = trim(substr($objectContent, $bodyStart, $bodyEnd - $bodyStart));

        // Check if it's a stream
        // Find stream start
        $streamStart = strpos($body, 'stream');
        if ($streamStart !== false) {
            // Dictionary is before stream
            $dictContent = trim(substr($body, 0, $streamStart));
            $dictionary = $this->parseDictionary($dictContent);

            // Get Length from dictionary if available
            $lengthEntry = $dictionary->getEntry('/Length');
            $length = null;
            if ($lengthEntry instanceof \PXP\PDF\Fpdf\Object\Base\PDFNumber) {
                $length = (int) $lengthEntry->getValue();
            }

            // Find endstream
            $streamEnd = strpos($body, 'endstream', $streamStart);
            if ($streamEnd !== false) {
                // Stream data is between "stream\n" and "\nendstream"
                $dataStart = $streamStart + 6; // "stream" is 6 chars
                // Skip whitespace/newlines after "stream"
                while ($dataStart < strlen($body) && preg_match('/[\r\n\s]/', $body[$dataStart])) {
                    $dataStart++;
                }

                // Extract data - use Length if available, otherwise extract up to endstream
                if ($length !== null && $length > 0) {
                    // Use exact length from dictionary
                    $streamData = substr($body, $dataStart, $length);
                } else {
                    // Extract data up to "endstream"
                    $dataEnd = $streamEnd;
                    // Remove trailing whitespace/newlines before "endstream"
                    while ($dataEnd > $dataStart && preg_match('/[\r\n\s]/', $body[$dataEnd - 1])) {
                        $dataEnd--;
                    }
                    $streamData = substr($body, $dataStart, $dataEnd - $dataStart);
                }

                $stream = new PDFStream($dictionary, $streamData, true);

                return $stream;
            }
        }

        // Parse as dictionary or other object
        return $this->parseValue($body);
    }

    /**
     * Parse a dictionary.
     */
    public function parseDictionary(string $dictContent): PDFDictionary
    {
        $dictStart = strpos($dictContent, '<<');
        $dictEnd = strrpos($dictContent, '>>');
        if ($dictStart === false || $dictEnd === false) {
            throw new FpdfException('Invalid dictionary format');
        }

        $content = substr($dictContent, $dictStart + 2, $dictEnd - $dictStart - 2);
        $dict = new PDFDictionary();

        // Parse key-value pairs
        $pos = 0;
        $length = strlen($content);

        while ($pos < $length) {
            // Skip whitespace
            while ($pos < $length && preg_match('/\s/', $content[$pos])) {
                $pos++;
            }

            if ($pos >= $length) {
                break;
            }

            // Parse key (must start with /)
            if ($content[$pos] !== '/') {
                break;
            }

            $keyEnd = $pos + 1;
            while ($keyEnd < $length && !preg_match('/\s/', $content[$keyEnd]) && $content[$keyEnd] !== '/') {
                $keyEnd++;
            }

            $key = substr($content, $pos, $keyEnd - $pos);
            $pos = $keyEnd;

            // Skip whitespace
            while ($pos < $length && preg_match('/\s/', $content[$pos])) {
                $pos++;
            }

            // Parse value
            $value = $this->parseValueAt($content, $pos, $pos);
            $dict->addEntry($key, $value);
        }

        return $dict;
    }

    /**
     * Parse an array.
     */
    public function parseArray(string $arrayContent): PDFArray
    {
        $arrayStart = strpos($arrayContent, '[');
        $arrayEnd = strrpos($arrayContent, ']');
        if ($arrayStart === false || $arrayEnd === false) {
            throw new FpdfException('Invalid array format');
        }

        $content = substr($arrayContent, $arrayStart + 1, $arrayEnd - $arrayStart - 1);
        $array = new PDFArray();

        $pos = 0;
        $length = strlen($content);

        while ($pos < $length) {
            // Skip whitespace
            while ($pos < $length && preg_match('/\s/', $content[$pos])) {
                $pos++;
            }

            if ($pos >= $length) {
                break;
            }

            $value = $this->parseValueAt($content, $pos, $pos);
            $array->add($value);
        }

        return $array;
    }

    /**
     * Parse a value at a specific position.
     */
    private function parseValueAt(string $content, int $start, int &$end): PDFObjectInterface|string|int|float
    {
        // Skip whitespace
        while ($start < strlen($content) && preg_match('/\s/', $content[$start])) {
            $start++;
        }

        $char = $content[$start] ?? '';

        // Reference
        if (preg_match('/^(\d+)\s+(\d+)\s+R/', substr($content, $start), $matches)) {
            $end = $start + strlen($matches[0]);

            return new PDFReference((int) $matches[1], (int) $matches[2]);
        }

        // Dictionary
        if (str_starts_with(substr($content, $start), '<<')) {
            $depth = 0;
            $pos = $start;
            while ($pos < strlen($content)) {
                if (substr($content, $pos, 2) === '<<') {
                    $depth++;
                    $pos += 2;
                } elseif (substr($content, $pos, 2) === '>>') {
                    $depth--;
                    $pos += 2;
                    if ($depth === 0) {
                        $end = $pos;

                        return $this->parseDictionary(substr($content, $start, $pos - $start));
                    }
                } else {
                    $pos++;
                }
            }
        }

        // Array
        if ($char === '[') {
            $depth = 0;
            $pos = $start;
            while ($pos < strlen($content)) {
                if ($content[$pos] === '[') {
                    $depth++;
                } elseif ($content[$pos] === ']') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $pos + 1;

                        return $this->parseArray(substr($content, $start, $pos + 1 - $start));
                    }
                }
                $pos++;
            }
        }

        // Name
        if ($char === '/') {
            $end = $start + 1;
            while ($end < strlen($content) && !preg_match('/[\s\/\[\]<>()]/', $content[$end])) {
                $end++;
            }

            return new PDFName(substr($content, $start, $end - $start));
        }

        // String (literal)
        if ($char === '(') {
            $pos = $start + 1;
            $depth = 1;
            $escaped = false;

            while ($pos < strlen($content) && $depth > 0) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($content[$pos] === '\\') {
                    $escaped = true;
                } elseif ($content[$pos] === '(') {
                    $depth++;
                } elseif ($content[$pos] === ')') {
                    $depth--;
                }
                $pos++;
            }

            $end = $pos;
            $stringContent = substr($content, $start + 1, $pos - $start - 2);

            return PDFString::fromPDFString('(' . $stringContent . ')');
        }

        // String (hex)
        if ($char === '<') {
            $end = strpos($content, '>', $start);
            if ($end === false) {
                throw new FpdfException('Invalid hex string');
            }
            $end++;
            $hexContent = substr($content, $start, $end - $start);

            return PDFString::fromPDFString($hexContent);
        }

        // Number
        if (preg_match('/^([+-]?\d+\.?\d*)/', substr($content, $start), $matches)) {
            $end = $start + strlen($matches[1]);
            $value = $matches[1];

            return new PDFNumber(str_contains($value, '.') ? (float) $value : (int) $value);
        }

        // Boolean
        if (preg_match('/^(true|false)/', substr($content, $start), $matches)) {
            $end = $start + strlen($matches[1]);

            return new PDFBoolean($matches[1] === 'true');
        }

        // Null
        if (preg_match('/^null/', substr($content, $start))) {
            $end = $start + 4;

            return new PDFNull();
        }

        throw new FpdfException('Unable to parse value at position ' . $start);
    }

    /**
     * Parse a value (general entry point).
     */
    private function parseValue(string $content): PDFObjectInterface|string|int|float
    {
        $pos = 0;

        return $this->parseValueAt($content, 0, $pos);
    }

    /**
     * Parse a reference.
     */
    public function parseReference(string $refContent): PDFReference
    {
        if (preg_match('/(\d+)\s+(\d+)\s+R/', $refContent, $matches)) {
            return new PDFReference((int) $matches[1], (int) $matches[2]);
        }

        throw new FpdfException('Invalid reference format: ' . $refContent);
    }

    /**
     * Parse a single object by object number (for lazy loading from file).
     *
     * @param string $filePath PDF file path
     * @param FileIOInterface $fileIO File IO interface for reading
     * @param int $objectNumber Object number to parse
     * @param PDFXrefTable $xrefTable Xref table for object locations
     * @return PDFObjectNode|null Parsed object node or null if not found
     */
    public function parseObjectByNumberFromFile(
        string $filePath,
        FileIOInterface $fileIO,
        int $objectNumber,
        PDFXrefTable $xrefTable
    ): ?PDFObjectNode {
        $xrefEntry = $xrefTable->getEntry($objectNumber);
        if ($xrefEntry === null || $xrefEntry->isFree()) {
            return null;
        }

        $offset = $xrefEntry->getOffset();
        $objectContent = $this->extractObjectContentFromFile($filePath, $fileIO, $offset, $objectNumber, $xrefEntry->getGeneration());

        if ($objectContent === null) {
            return null;
        }

        $object = $this->parseObject($objectContent, $objectNumber);
        return new PDFObjectNode($objectNumber, $object, $xrefEntry->getGeneration(), $offset);
    }

    /**
     * Parse a single object by object number (for lazy loading from memory).
     *
     * @param string $content Raw PDF content
     * @param int $objectNumber Object number to parse
     * @param PDFXrefTable $xrefTable Xref table for object locations
     * @return PDFObjectNode|null Parsed object node or null if not found
     */
    public function parseObjectByNumber(string $content, int $objectNumber, PDFXrefTable $xrefTable): ?PDFObjectNode
    {
        $xrefEntry = $xrefTable->getEntry($objectNumber);
        if ($xrefEntry === null || $xrefEntry->isFree()) {
            return null;
        }

        $offset = $xrefEntry->getOffset();
        $objectContent = $this->extractObjectContent($content, $offset, $objectNumber, $xrefEntry->getGeneration());

        if ($objectContent === null) {
            return null;
        }

        $object = $this->parseObject($objectContent, $objectNumber);
        return new PDFObjectNode($objectNumber, $object, $xrefEntry->getGeneration(), $offset);
    }

    /**
     * Extract object content from file using chunked reading.
     *
     * @param string $filePath PDF file path
     * @param FileIOInterface $fileIO File IO interface
     * @param int $offset Byte offset from xref table
     * @param int $objectNumber Object number
     * @param int $generation Generation number
     * @return string|null Object content or null if not found
     */
    private function extractObjectContentFromFile(
        string $filePath,
        FileIOInterface $fileIO,
        int $offset,
        int $objectNumber,
        int $generation
    ): ?string {
        // Read a chunk around the offset (64KB before and after, or to file boundaries)
        $chunkSize = 65536; // 64KB
        $fileSize = filesize($filePath);

        // Calculate read boundaries
        $readStart = max(0, $offset - $chunkSize);
        $readEnd = min($fileSize, $offset + $chunkSize);
        $readLength = $readEnd - $readStart;

        // Read chunk
        $chunk = $fileIO->readFileChunk($filePath, $readLength, $readStart);

        // Adjust offset to be relative to chunk start
        $relativeOffset = $offset - $readStart;

        // Search for object start in chunk
        $objStartMarker = (string) $objectNumber . ' ' . $generation . ' obj';
        $objStartInChunk = strpos($chunk, $objStartMarker, max(0, $relativeOffset - 100));

        if ($objStartInChunk === false) {
            // Object not found in chunk, try reading larger area
            $largerChunkSize = 262144; // 256KB
            $readStart = max(0, $offset - $largerChunkSize);
            $readEnd = min($fileSize, $offset + $largerChunkSize);
            $readLength = $readEnd - $readStart;
            $chunk = $fileIO->readFileChunk($filePath, $readLength, $readStart);
            $relativeOffset = $offset - $readStart;
            $objStartInChunk = strpos($chunk, $objStartMarker, max(0, $relativeOffset - 100));

            if ($objStartInChunk === false) {
                return null;
            }
        }

        // Find endobj
        $objEndInChunk = strpos($chunk, 'endobj', $objStartInChunk);
        if ($objEndInChunk === false) {
            // endobj might be outside chunk, read more
            $remainingStart = $readStart + $objStartInChunk;
            $remainingLength = min($fileSize - $remainingStart, 1048576); // Read up to 1MB more
            $remainingChunk = $fileIO->readFileChunk($filePath, $remainingLength, $remainingStart);
            $objEndInRemaining = strpos($remainingChunk, 'endobj');

            if ($objEndInRemaining === false) {
                return null;
            }

            // Combine chunks
            $objStartAbsolute = $readStart + $objStartInChunk;
            $objEndAbsolute = $remainingStart + $objEndInRemaining + 6;
            $objectLength = $objEndAbsolute - $objStartAbsolute;
            $objectContent = $fileIO->readFileChunk($filePath, $objectLength, $objStartAbsolute);

            return $objectContent;
        }

        // Extract object content from chunk
        $objStartAbsolute = $readStart + $objStartInChunk;
        $objEndAbsolute = $readStart + $objEndInChunk + 6;
        $objectLength = $objEndAbsolute - $objStartAbsolute;

        // If object fits in chunk, return it
        if ($objStartAbsolute >= $readStart && $objEndAbsolute <= $readEnd) {
            return substr($chunk, $objStartInChunk, $objEndInChunk - $objStartInChunk + 6);
        }

        // Otherwise read exact content
        $objectContent = $fileIO->readFileChunk($filePath, $objectLength, $objStartAbsolute);
        return $objectContent;
    }
}
