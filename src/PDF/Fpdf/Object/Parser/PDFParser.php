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

use const PREG_OFFSET_CAPTURE;
use const PREG_SET_ORDER;
use function array_map;
use function array_slice;
use function count;
use function filesize;
use function in_array;
use function is_numeric;
use function ltrim;
use function max;
use function microtime;
use function min;
use function preg_match;
use function preg_match_all;
use function preg_split;
use function realpath;
use function round;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strpos;
use function strrpos;
use function substr;
use function trim;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use PXP\PDF\Fpdf\Cache\NullCache;
use PXP\PDF\Fpdf\Exception\FpdfException;
use PXP\PDF\Fpdf\IO\FileIOInterface;
use PXP\PDF\Fpdf\Log\NullLogger;
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
use PXP\PDF\Fpdf\Tree\PDFObjectRegistry;
use PXP\PDF\Fpdf\Xref\PDFXrefTable;
use PXP\PDF\Fpdf\Xref\XrefStreamParser;

/**
 * Main parser for reading PDFs into tree structure.
 */
final class PDFParser
{
    private LoggerInterface $logger;
    private CacheItemPoolInterface $cache;

    public function __construct(
        ?LoggerInterface $logger = null,
        ?CacheItemPoolInterface $cache = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
        $this->cache  = $cache ?? new NullCache;
    }

    /**
     * Parse a full PDF document from file path (file-based lazy loading).
     * Only reads header, xref table, and trailer - objects are loaded on-demand.
     *
     * @param string          $filePath Path to PDF file
     * @param FileIOInterface $fileIO   File IO interface for reading
     *
     * @return PDFDocument Parsed document with file-based lazy loading
     */
    public function parseDocumentFromFile(string $filePath, FileIOInterface $fileIO): PDFDocument
    {
        $absolutePath = realpath($filePath) ?: $filePath;
        $startTime    = microtime(true);

        $this->logger->debug('PDF file parsing started', [
            'file_path' => $absolutePath,
            'file_size' => filesize($filePath),
        ]);

        $fileSize = filesize($filePath);

        // Read only header (first 8KB should be enough)
        $headerChunk = $fileIO->readFileChunk($filePath, min(8192, $fileSize), 0);
        $header      = PDFHeader::parse($headerChunk);
        $version     = $header !== null ? $header->getVersion() : '1.3';

        $this->logger->debug('PDF header parsed', [
            'file_path' => $absolutePath,
            'version'   => $version,
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
            'file_path'     => $absolutePath,
            'xref_position' => $xrefPos,
        ]);

        // Read xref table and trailer (read enough to get both)
        $readSize       = min(131072, $fileSize - $xrefPos); // Read up to 128KB from xref
        $xrefAndTrailer = $fileIO->readFileChunk($filePath, $readSize, $xrefPos);

        // Check if it's a traditional xref table or xref stream
        $isTraditionalXref = str_starts_with($xrefAndTrailer, 'xref');

        if (!$isTraditionalXref) {
            // This is an xref stream
            $xrefTable = $this->parseXrefStreamFromFile($filePath, $fileIO, $fileSize, $xrefPos, $absolutePath);

            $xrefEntryCount = count($xrefTable->getAllEntries());
            $this->logger->debug('Xref stream parsed (with Prev references)', [
                'file_path'   => $absolutePath,
                'entry_count' => $xrefEntryCount,
            ]);

            // Create registry with file-based lazy loading context
            $registry = new PDFObjectRegistry(null, $this, $xrefTable, $filePath, $fileIO, $this->cache, $this->logger);

            // Create document with lazy-loading enabled registry
            $document = new PDFDocument($version, $registry);

            // Mirror xref entries into document's xref table for callers that expect it
            $document->getXrefTable()->mergeEntries($xrefTable);

            // Set header version
            if ($header !== null) {
                $document->getHeader()->setVersion($header->getVersion());
            }

            // Parse trailer from xref stream (trailer info is in stream dictionary)
            // We already parsed the stream in parseXrefStreamFromFile, so we can extract it from there
            // For now, the trailer info will be set during xref stream parsing
            // We'll parse it again to get trailer info
            $readSize = min(100, $fileSize - $xrefPos);
            $chunk    = $fileIO->readFileChunk($filePath, $readSize, $xrefPos);

            if (preg_match('/(\d+)\s+(\d+)\s+obj/', $chunk, $matches)) {
                $objectNumber  = (int) $matches[1];
                $generation    = (int) $matches[2];
                $objectContent = $this->extractObjectContentFromFile($filePath, $fileIO, $xrefPos, $objectNumber, $generation);

                if ($objectContent !== null) {
                    $stream = $this->parseObject($objectContent, $objectNumber);

                    if ($stream instanceof PDFStream) {
                        $this->parseTrailerFromStreamDict($stream->getDictionary(), $document);
                    }
                }
            }

            // Only parse root object immediately (needed for document structure)
            $rootRef = $document->getTrailer()->getRoot();

            if ($rootRef !== null) {
                $this->logger->debug('Loading root object', [
                    'file_path'     => $absolutePath,
                    'object_number' => $rootRef->getObjectNumber(),
                ]);
                $rootNode = $registry->get($rootRef->getObjectNumber());

                if ($rootNode !== null) {
                    $document->setRoot($rootNode);
                }
            }

            $duration = (microtime(true) - $startTime) * 1000;
            $this->logger->info('PDF file parsing completed', [
                'file_path'    => $absolutePath,
                'duration_ms'  => round($duration, 2),
                'version'      => $version,
                'object_count' => $xrefEntryCount,
            ]);

            return $document;
        }

        $xrefEnd = strpos($xrefAndTrailer, 'trailer');

        if ($xrefEnd === false) {
            // If trailer not found in chunk, read more
            $readSize       = $fileSize - $xrefPos;
            $xrefAndTrailer = $fileIO->readFileChunk($filePath, $readSize, $xrefPos);
            $xrefEnd        = strpos($xrefAndTrailer, 'trailer');

            if ($xrefEnd === false) {
                $this->logger->error('Trailer not found', [
                    'file_path' => $absolutePath,
                ]);

                throw new FpdfException('Invalid PDF: trailer not found');
            }
        }

        // Parse xref table and handle Prev references recursively
        $xrefTable = $this->parseXrefTableFromFile($filePath, $fileIO, $fileSize, $xrefPos, $absolutePath);

        $xrefEntryCount = count($xrefTable->getAllEntries());
        $this->logger->debug('Xref table parsed (with Prev references)', [
            'file_path'   => $absolutePath,
            'entry_count' => $xrefEntryCount,
        ]);

        // Create registry with file-based lazy loading context
        $registry = new PDFObjectRegistry(null, $this, $xrefTable, $filePath, $fileIO, $this->cache, $this->logger);

        // Create document with lazy-loading enabled registry
        $document = new PDFDocument($version, $registry);

        // Mirror xref entries into the document's xref table
        $document->getXrefTable()->mergeEntries($xrefTable);

        // Set header version
        if ($header !== null) {
            $document->getHeader()->setVersion($header->getVersion());
        }

        // Parse trailer (we already parsed it during xref parsing, but need to set it on document)
        $readSize       = min(131072, $fileSize - $xrefPos);
        $xrefAndTrailer = $fileIO->readFileChunk($filePath, $readSize, $xrefPos);
        $xrefEnd        = strpos($xrefAndTrailer, 'trailer');

        if ($xrefEnd === false) {
            $readSize       = $fileSize - $xrefPos;
            $xrefAndTrailer = $fileIO->readFileChunk($filePath, $readSize, $xrefPos);
            $xrefEnd        = strpos($xrefAndTrailer, 'trailer');
        }

        if ($xrefEnd !== false) {
            $trailerSection = substr($xrefAndTrailer, $xrefEnd);
            $this->parseTrailer($trailerSection, $document);
        }

        $this->logger->debug('Trailer parsed', [
            'file_path'   => $absolutePath,
            'root_object' => $document->getTrailer()->getRoot()?->getObjectNumber(),
            'info_object' => $document->getTrailer()->getInfo()?->getObjectNumber(),
        ]);

        // Only parse root object immediately (needed for document structure)
        $rootRef = $document->getTrailer()->getRoot();

        if ($rootRef !== null) {
            $this->logger->debug('Loading root object', [
                'file_path'     => $absolutePath,
                'object_number' => $rootRef->getObjectNumber(),
            ]);
            $rootNode = $registry->get($rootRef->getObjectNumber());

            if ($rootNode !== null) {
                $document->setRoot($rootNode);
            }
        }

        $duration = (microtime(true) - $startTime) * 1000;
        $this->logger->info('PDF file parsing completed', [
            'file_path'    => $absolutePath,
            'duration_ms'  => round($duration, 2),
            'version'      => $version,
            'object_count' => $xrefEntryCount,
        ]);

        return $document;
    }

    /**
     * Parse a full PDF document from string content.
     * Uses memory-based lazy loading.
     */
    public function parseDocument(string $content): PDFDocument
    {
        $startTime     = microtime(true);
        $contentLength = strlen($content);

        $this->logger->debug('PDF document parsing started (memory-based)', [
            'content_length' => $contentLength,
        ]);

        // Parse header first to get version
        $header  = PDFHeader::parse($content);
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

        // Check if it's a traditional xref table or xref stream
        // Be lenient: startxref can point near the "xref" keyword (whitespace, or slightly off by a few bytes)
        $foundXrefPos = $this->findNearbyKeyword($content, 'xref', $xrefPos, 128);

        if ($foundXrefPos === false) {
            // This is an xref stream
            $visitedPositions  = [];
            $xrefTable         = $this->parseXrefStream($content, $xrefPos, $visitedPositions);
            $isTraditionalXref = false;
        } else {
            // Parse xref table and handle Prev references recursively
            // Use the actual location of 'xref' we found
            $xrefPos           = $foundXrefPos;
            $visitedPositions  = [];
            $xrefTable         = $this->parseXrefTable($content, $xrefPos, $visitedPositions);
            $isTraditionalXref = true;
        }

        $xrefEntryCount = count($xrefTable->getAllEntries());
        $this->logger->debug('Xref table parsed', [
            'entry_count' => $xrefEntryCount,
        ]);

        // Create registry with native lazy loading context
        $registry = new PDFObjectRegistry($content, $this, $xrefTable, null, null, $this->cache, $this->logger);

        // Create document with lazy-loading enabled registry
        $document = new PDFDocument($version, $registry);

        // Mirror parsed xref entries into the document's xref table so callers that expect
        // immediate access to xref entries (e.g., feature tests) can read them.
        $document->getXrefTable()->mergeEntries($xrefTable);

        // Set header version
        if ($header !== null) {
            $document->getHeader()->setVersion($header->getVersion());
        }

        // Note: We don't copy xref entries to document's xref table here.
        // The registry has the full xref table for lazy loading.
        // The document's xref table will be rebuilt during serialization if needed.
        // Copying all entries (potentially 20,000+ for large PDFs) causes memory issues.

        // Parse trailer (for traditional xref) or extract from stream dictionary (for xref stream)
        if ($isTraditionalXref) {
            $xrefEnd = strpos($content, 'trailer', $xrefPos);

            if ($xrefEnd !== false) {
                $trailerSection = substr($content, $xrefEnd);
                $this->parseTrailer($trailerSection, $document);
            }
        } else {
            // For xref stream, trailer info is in the stream dictionary
            $chunk = substr($content, $xrefPos, 100);

            if (preg_match('/(\d+)\s+(\d+)\s+obj/', $chunk, $matches)) {
                $objectNumber  = (int) $matches[1];
                $generation    = (int) $matches[2];
                $objectContent = $this->extractObjectContent($content, $xrefPos, $objectNumber, $generation);

                if ($objectContent !== null) {
                    $stream = $this->parseObject($objectContent, $objectNumber);

                    if ($stream instanceof PDFStream) {
                        $this->parseTrailerFromStreamDict($stream->getDictionary(), $document);
                    }
                }
            }
        }

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
            'duration_ms'  => round($duration, 2),
            'version'      => $version,
            'object_count' => $xrefEntryCount,
        ]);

        return $document;
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
        $bodyEnd   = strrpos($objectContent, 'endobj');

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
            $dictionary  = $this->parseDictionary($dictContent);

            // Get Length from dictionary if available
            $lengthEntry = $dictionary->getEntry('/Length');
            $length      = null;

            if ($lengthEntry instanceof PDFNumber) {
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

                return new PDFStream($dictionary, $streamData, true);
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
        $dictEnd   = strrpos($dictContent, '>>');

        if ($dictStart === false || $dictEnd === false) {
            throw new FpdfException('Invalid dictionary format');
        }

        $content = substr($dictContent, $dictStart + 2, $dictEnd - $dictStart - 2);
        $dict    = new PDFDictionary;

        // Parse key-value pairs
        $pos    = 0;
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

            // Stop at whitespace, '/', '[' (start of array), '<' (start of dictionary or hex string), or '(' (start of literal string)
            while ($keyEnd < $length && !preg_match('/\s/', $content[$keyEnd]) && $content[$keyEnd] !== '/' && $content[$keyEnd] !== '[' && $content[$keyEnd] !== '<' && $content[$keyEnd] !== '(') {
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
        $arrayEnd   = strrpos($arrayContent, ']');

        if ($arrayStart === false || $arrayEnd === false) {
            throw new FpdfException('Invalid array format');
        }

        $content = substr($arrayContent, $arrayStart + 1, $arrayEnd - $arrayStart - 1);
        $array   = new PDFArray;

        $pos    = 0;
        $length = strlen($content);

        while ($pos < $length) {
            // Skip whitespace
            while ($pos < $length && preg_match('/\s/', $content[$pos])) {
                $pos++;
            }

            if ($pos >= $length) {
                break;
            }

            $end   = $pos;
            $value = $this->parseValueAt($content, $pos, $end);
            $array->add($value);
            $pos = $end; // CRITICAL: Advance position to end of parsed value
        }

        return $array;
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
     * @param string          $filePath     PDF file path
     * @param FileIOInterface $fileIO       File IO interface for reading
     * @param int             $objectNumber Object number to parse
     * @param PDFXrefTable    $xrefTable    Xref table for object locations
     *
     * @return null|PDFObjectNode Parsed object node or null if not found
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

        $offset        = $xrefEntry->getOffset();
        $objectContent = $this->extractObjectContentFromFile($filePath, $fileIO, $offset, $objectNumber, $xrefEntry->getGeneration());

        if ($objectContent === null) {
            return null;
        }

        $object = $this->parseObject($objectContent, $objectNumber);

        return new PDFObjectNode($objectNumber, $object, $xrefEntry->getGeneration(), $offset);
    }

    /**
     * Parse a compressed object referenced by an object stream (ObjStm).
     *
     * @param string          $filePath           PDF file path
     * @param FileIOInterface $fileIO             File IO interface
     * @param int             $objectStreamNumber Object number of the ObjStm
     * @param int             $index              Index of the subobject inside the object stream (0-based)
     * @param PDFXrefTable    $xrefTable          Xref table (for resolving object stream)
     *
     * @return null|PDFObjectNode Parsed object node or null if not found
     */
    public function parseObjectFromObjectStreamInFile(
        string $filePath,
        FileIOInterface $fileIO,
        int $objectStreamNumber,
        int $index,
        PDFXrefTable $xrefTable
    ): ?PDFObjectNode {
        // First load the object stream itself
        $objStreamNode = $this->parseObjectByNumberFromFile($filePath, $fileIO, $objectStreamNumber, $xrefTable);

        if ($objStreamNode === null) {
            return null;
        }

        $objStreamValue = $objStreamNode->getValue();

        if (!($objStreamValue instanceof PDFStream)) {
            return null;
        }

        $dict       = $objStreamValue->getDictionary();
        $nEntry     = $dict->getEntry('/N');
        $firstEntry = $dict->getEntry('/First');

        if (!($nEntry instanceof PDFNumber) || !($firstEntry instanceof PDFNumber)) {
            return null;
        }

        $n     = (int) $nEntry->getValue();
        $first = (int) $firstEntry->getValue();

        $decoded = $objStreamValue->getDecodedData();

        // Extract header tokens (first part before object data)
        $headerRaw = substr($decoded, 0, $first);
        $tokens    = preg_split('/\s+/', trim($headerRaw));

        if ($tokens === false) {
            return null;
        }

        // Expect 2*N tokens (objNum offset pairs) - be tolerant about ordering
        if (count($tokens) < 2 * $n) {
            return null;
        }

        $objNums = [];
        $offsets = [];

        // Try interpret as pairs: obj1 offset1 obj2 offset2 ...
        $pairsOk = true;

        for ($i = 0; $i < $n; $i++) {
            $obj = $tokens[$i * 2] ?? null;
            $off = $tokens[$i * 2 + 1] ?? null;

            if (!is_numeric($obj) || !is_numeric($off)) {
                $pairsOk = false;

                break;
            }
            $objNums[] = (int) $obj;
            $offsets[] = (int) $off;
        }

        // If pairs interpretation yields non-monotonic offsets or failed, try alternate format: first N are object numbers then N offsets
        $offsetsMonotonic = true;

        for ($i = 1; $i < count($offsets); $i++) {
            if ($offsets[$i] < $offsets[$i - 1]) {
                $offsetsMonotonic = false;

                break;
            }
        }

        if (!$pairsOk || !$offsetsMonotonic) {
            // Try alternate layout: first N tokens are object numbers, next N are offsets
            $altObjNums = array_map('intval', array_slice($tokens, 0, $n));
            $altOffsets = array_map('intval', array_slice($tokens, $n, $n));

            // Validate
            if (count($altObjNums) === $n && count($altOffsets) === $n) {
                $objNums = $altObjNums;
                $offsets = $altOffsets;
            } else {
                // Can't interpret header
                return null;
            }
        }

        if ($index < 0 || $index >= $n) {
            return null;
        }

        $subObjNumber = $objNums[$index];
        $subOffset    = $offsets[$index];
        $start        = $first + $subOffset;
        $end          = strlen($decoded);

        if ($index + 1 < $n) {
            $end = $first + $offsets[$index + 1];
        }

        $subData = substr($decoded, $start, $end - $start);

        // Construct a fake object wrapper for parsing: "<objNum> 0 obj\n<subData>\nendobj"
        $fake = $subObjNumber . " 0 obj\n" . $subData . "\nendobj";

        try {
            $object = $this->parseObject($fake, $subObjNumber);
        } catch (FpdfException $e) {
            return null;
        }

        // Return a node with offset -1 since we don't have a direct byte offset for subobjects
        return new PDFObjectNode($subObjNumber, $object, 0, -1);
    }

    /**
     * Parse a single object by object number (for lazy loading from memory).
     *
     * @param string       $content      Raw PDF content
     * @param int          $objectNumber Object number to parse
     * @param PDFXrefTable $xrefTable    Xref table for object locations
     *
     * @return null|PDFObjectNode Parsed object node or null if not found
     */
    public function parseObjectByNumber(string $content, int $objectNumber, PDFXrefTable $xrefTable): ?PDFObjectNode
    {
        $xrefEntry = $xrefTable->getEntry($objectNumber);

        if ($xrefEntry === null || $xrefEntry->isFree()) {
            return null;
        }

        $offset        = $xrefEntry->getOffset();
        $objectContent = $this->extractObjectContent($content, $offset, $objectNumber, $xrefEntry->getGeneration());

        if ($objectContent === null) {
            return null;
        }

        $object = $this->parseObject($objectContent, $objectNumber);

        return new PDFObjectNode($objectNumber, $object, $xrefEntry->getGeneration(), $offset);
    }

    /**
     * Find xref table position in file by searching for startxref keyword.
     *
     * @param string          $filePath PDF file path
     * @param FileIOInterface $fileIO   File IO interface
     * @param int             $fileSize File size in bytes
     *
     * @return false|int Xref position or false if not found
     */
    private function findXrefTableInFile(string $filePath, FileIOInterface $fileIO, int $fileSize): false|int
    {
        $absolutePath = realpath($filePath) ?: $filePath;
        // Search backwards from end of file for startxref keyword
        // Read chunks from the end
        $chunkSize     = 65536; // 64KB chunks
        $searchStart   = max(0, $fileSize - $chunkSize);
        $chunkCount    = 0;
        $maxIterations = 1000; // Safety limit to prevent infinite loops

        while ($searchStart >= 0 && $chunkCount < $maxIterations) {
            $chunkCount++;
            $readSize = min($chunkSize, $fileSize - $searchStart);
            $chunk    = $fileIO->readFileChunk($filePath, $readSize, $searchStart);

            // Search for startxref pattern: startxref[\s]*[\r\n]+([0-9]+)[\s]*[\r\n]+%%EOF
            $startxrefMatches = [];

            if (
                preg_match_all(
                    '/(?<=[\r\n])startxref[\s]*[\r\n]+([0-9]+)[\s]*[\r\n]+%%EOF/i',
                    $chunk,
                    $startxrefMatches,
                    PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
                ) > 0
            ) {
                // Use the last match (most recent xref)
                $lastMatch       = $startxrefMatches[count($startxrefMatches) - 1];
                $startxrefOffset = (int) $lastMatch[1][0];
                $matchPosition   = $lastMatch[0][1];

                $this->logger->debug('Startxref found in chunk', [
                    'file_path'        => $absolutePath,
                    'chunk_number'     => $chunkCount,
                    'startxref_offset' => $startxrefOffset,
                    'match_position'   => $searchStart + $matchPosition,
                ]);

                // Return the offset from startxref (absolute position in file)
                return $startxrefOffset;
            }

            // Don't search too far back (startxref should be in last 1MB)
            if ($fileSize - $searchStart > 1048576) {
                $this->logger->debug('Startxref search limit reached', [
                    'file_path'       => $absolutePath,
                    'chunks_searched' => $chunkCount,
                ]);

                break;
            }

            // Move search window backwards
            $newSearchStart = max(0, $searchStart - $chunkSize + 100); // Overlap by 100 bytes

            // Break if we're not making progress
            if ($newSearchStart >= $searchStart) {
                $this->logger->debug('Startxref search stopped making progress', [
                    'file_path'       => $absolutePath,
                    'chunks_searched' => $chunkCount,
                    'final_position'  => $searchStart,
                ]);

                break;
            }

            $searchStart = $newSearchStart;
        }

        if ($chunkCount >= $maxIterations) {
            $this->logger->warning('Startxref search hit maximum iteration limit', [
                'file_path'       => $absolutePath,
                'chunks_searched' => $chunkCount,
            ]);
        }

        return false;
    }

    /**
     * Parse xref table from file at given offset, handling Prev references recursively.
     *
     * @param string          $filePath         PDF file path
     * @param FileIOInterface $fileIO           File IO interface
     * @param int             $fileSize         File size in bytes
     * @param int             $xrefPos          Xref position
     * @param string          $absolutePath     Absolute file path for logging
     * @param array<int>      $visitedPositions Track visited positions to prevent cycles
     *
     * @return PDFXrefTable Merged xref table including all Prev references
     */
    private function parseXrefTableFromFile(
        string $filePath,
        FileIOInterface $fileIO,
        int $fileSize,
        int $xrefPos,
        string $absolutePath,
        array &$visitedPositions = []
    ): PDFXrefTable {
        // Check for cycles
        if (in_array($xrefPos, $visitedPositions, true)) {
            $this->logger->warning('Circular Prev reference detected, breaking cycle', [
                'file_path'         => $absolutePath,
                'xref_position'     => $xrefPos,
                'visited_positions' => $visitedPositions,
            ]);

            // Return empty table to break the cycle
            return new PDFXrefTable;
        }

        $visitedPositions[] = $xrefPos;

        // Read xref table and trailer
        $readSize       = min(131072, $fileSize - $xrefPos);
        $xrefAndTrailer = $fileIO->readFileChunk($filePath, $readSize, $xrefPos);

        // Check if it's a traditional xref table or xref stream
        $isTraditionalXref = str_starts_with($xrefAndTrailer, 'xref');

        if (!$isTraditionalXref) {
            // This is an xref stream
            return $this->parseXrefStreamFromFile($filePath, $fileIO, $fileSize, $xrefPos, $absolutePath, $visitedPositions);
        }

        $xrefEnd = strpos($xrefAndTrailer, 'trailer');

        if ($xrefEnd === false) {
            // If trailer not found in chunk, read more
            $readSize       = $fileSize - $xrefPos;
            $xrefAndTrailer = $fileIO->readFileChunk($filePath, $readSize, $xrefPos);
            $xrefEnd        = strpos($xrefAndTrailer, 'trailer');

            if ($xrefEnd === false) {
                throw new FpdfException('Invalid PDF: trailer not found');
            }
        }

        // Parse this xref table
        $xrefContent = substr($xrefAndTrailer, 4, $xrefEnd - 4); // Skip "xref" keyword
        $xrefTable   = new PDFXrefTable;
        $xrefTable->parseFromString($xrefContent);

        // Parse trailer to get Prev offset
        $trailerSection = substr($xrefAndTrailer, $xrefEnd);
        $tempDocument   = new PDFDocument;
        $prevOffset     = $this->parseTrailer($trailerSection, $tempDocument);

        // If there's a Prev reference, validate it and parse it recursively and merge
        if ($prevOffset !== null && $prevOffset > 0) {
            if ($prevOffset < $fileSize) {
                $this->logger->debug('Found Prev reference, parsing previous xref table', [
                    'file_path'   => $absolutePath,
                    'prev_offset' => $prevOffset,
                ]);

                $prevXrefTable = $this->parseXrefTableFromFile($filePath, $fileIO, $fileSize, $prevOffset, $absolutePath, $visitedPositions);
                // Merge entries: merge previous into current, so newer entries (current) override older ones (prev)
                $xrefTable->mergeEntries($prevXrefTable);
            } else {
                $this->logger->warning('Prev reference outside file bounds, ignoring', ['file_path' => $absolutePath, 'prev_offset' => $prevOffset]);
            }
        }

        return $xrefTable;
    }

    /**
     * Parse xref table from content at given offset, handling Prev references recursively.
     *
     * @param string     $content          PDF content
     * @param int        $xrefPos          Xref position
     * @param array<int> $visitedPositions Track visited positions to prevent cycles
     *
     * @return PDFXrefTable Merged xref table including all Prev references
     */
    private function parseXrefTable(string $content, int $xrefPos, array &$visitedPositions = []): PDFXrefTable
    {
        // Check for cycles
        if (in_array($xrefPos, $visitedPositions, true)) {
            $this->logger->warning('Circular Prev reference detected, breaking cycle', [
                'xref_position'     => $xrefPos,
                'visited_positions' => $visitedPositions,
            ]);

            // Return empty table to break the cycle
            return new PDFXrefTable;
        }

        $visitedPositions[] = $xrefPos;
        // Check if it's a traditional xref table or xref stream
        // Be lenient: startxref can point near the "xref" keyword (whitespace, or slightly off by a few bytes)
        $foundXrefPos = $this->findNearbyKeyword($content, 'xref', $xrefPos, 128);

        if ($foundXrefPos === false) {
            // This is an xref stream
            $visitedPositions = [];

            return $this->parseXrefStream($content, $xrefPos, $visitedPositions);
        }

        // Update to the actual 'xref' position we found
        $xrefPos = $foundXrefPos;

        $xrefEnd = strpos($content, 'trailer', $xrefPos);

        if ($xrefEnd === false) {
            throw new FpdfException('Invalid PDF: trailer not found');
        }

        // Parse this xref table
        $xrefContent = substr($content, $xrefPos + 4, $xrefEnd - $xrefPos - 4); // Skip "xref" keyword
        $xrefTable   = new PDFXrefTable;
        $xrefTable->parseFromString($xrefContent);

        // Parse trailer to get Prev offset
        $trailerSection = substr($content, $xrefEnd);
        $tempDocument   = new PDFDocument;
        $prevOffset     = $this->parseTrailer($trailerSection, $tempDocument);

        // If there's a Prev reference, validate it and parse it recursively and merge
        if ($prevOffset !== null && $prevOffset > 0) {
            if ($prevOffset < strlen($content)) {
                $this->logger->debug('Found Prev reference, parsing previous xref table', [
                    'prev_offset' => $prevOffset,
                ]);

                $prevXrefTable = $this->parseXrefTable($content, $prevOffset);
                // Merge entries: merge previous into current, so newer entries (current) override older ones (prev)
                $xrefTable->mergeEntries($prevXrefTable);
            } else {
                $this->logger->warning('Prev reference outside content bounds, ignoring', ['prev_offset' => $prevOffset]);
            }
        }

        return $xrefTable;
    }

    /**
     * Find xref table position by searching for startxref keyword.
     *
     * @param string $content PDF content
     *
     * @return false|int Xref position or false if not found
     */
    private function findXrefTable(string $content): false|int
    {
        // Search for startxref pattern: startxref[\s]*[\r\n]+([0-9]+)[\s]*[\r\n]+%%EOF
        $startxrefMatches = [];

        if (
            preg_match_all(
                '/(?<=[\r\n])startxref[\s]*[\r\n]+([0-9]+)[\s]*[\r\n]+%%EOF/i',
                $content,
                $startxrefMatches,
                PREG_SET_ORDER,
            ) > 0
        ) {
            // Use the last match (most recent xref)
            $lastMatch       = $startxrefMatches[count($startxrefMatches) - 1];
            $startxrefOffset = (int) $lastMatch[1];

            $this->logger->debug('Startxref found in content', [
                'startxref_offset' => $startxrefOffset,
            ]);

            return $startxrefOffset;
        }

        return false;
    }

    /**
     * Parse trailer section and extract all relevant fields including Prev offset.
     *
     * @param string      $trailerSection The trailer section content
     * @param PDFDocument $document       The document to update
     *
     * @return null|int Previous xref offset if found, null otherwise
     */
    private function parseTrailer(string $trailerSection, PDFDocument $document): ?int
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

        $encryptMatch = [];

        if (preg_match('/\/Encrypt\s+(\d+)\s+(\d+)\s+R/', $trailerSection, $encryptMatch)) {
            $document->getTrailer()->setEncrypt(new PDFReference((int) $encryptMatch[1], (int) $encryptMatch[2]));
        }

        $idMatch = [];

        if (preg_match('/\/ID\s*\[\s*<\s*([^>]*)\s*>\s*<\s*([^>]*)\s*>\s*\]/i', $trailerSection, $idMatch)) {
            $document->getTrailer()->setId([$idMatch[1], $idMatch[2]]);
        }

        // Extract Prev offset for incremental updates
        $prevMatch = [];

        if (preg_match('/\/Prev\s+(\d+)/i', $trailerSection, $prevMatch)) {
            $prevOffset = (int) $prevMatch[1];

            if ($prevOffset > 0) {
                return $prevOffset;
            }
        }

        return null;
    }

    /**
     * Find the given keyword near the specified offset by searching a window both
     * forwards and backwards. Returns the position of the keyword or false.
     */
    private function findNearbyKeyword(string $content, string $keyword, int $offset, int $window = 128): false|int
    {
        $len     = strlen($content);
        $start   = max(0, $offset - $window);
        $end     = min($len, $offset + $window);
        $segment = substr($content, $start, $end - $start);

        $pos = strpos($segment, $keyword);

        if ($pos !== false) {
            return $start + $pos;
        }

        // Try searching backwards for the keyword (in case the segment begins after)
        $revPos = strrpos($segment, $keyword);

        if ($revPos !== false) {
            return $start + $revPos;
        }

        return false;
    }

    /**
     * Parse a value at a specific position.
     */
    private function parseValueAt(string $content, int $start, int &$end): float|int|PDFObjectInterface|string
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
            $pos   = $start;

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
            $depth       = 0;
            $pos         = $start;
            $inString    = false;
            $stringDepth = 0;
            $escaped     = false;

            while ($pos < strlen($content)) {
                $c = $content[$pos];

                // Handle string literals (parentheses)
                if ($c === '(' && !$inString && !$escaped) {
                    $inString    = true;
                    $stringDepth = 1;
                } elseif ($inString) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($c === '\\') {
                        $escaped = true;
                    } elseif ($c === '(') {
                        $stringDepth++;
                    } elseif ($c === ')') {
                        $stringDepth--;

                        if ($stringDepth === 0) {
                            $inString = false;
                        }
                    }
                } elseif ($c === '[') {
                    $depth++;
                } elseif ($c === ']') {
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
            $pos     = $start + 1;
            $depth   = 1;
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

            $end           = $pos;
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
            $end   = $start + strlen($matches[1]);
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

            return new PDFNull;
        }

        throw new FpdfException('Unable to parse value at position ' . $start);
    }

    /**
     * Parse a value (general entry point).
     */
    private function parseValue(string $content): float|int|PDFObjectInterface|string
    {
        $pos = 0;

        return $this->parseValueAt($content, 0, $pos);
    }

    /**
     * Extract object content from file using chunked reading.
     *
     * @param string          $filePath     PDF file path
     * @param FileIOInterface $fileIO       File IO interface
     * @param int             $offset       Byte offset from xref table
     * @param int             $objectNumber Object number
     * @param int             $generation   Generation number
     *
     * @return null|string Object content or null if not found
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
        $fileSize  = filesize($filePath);

        // Calculate read boundaries
        $readStart  = max(0, $offset - $chunkSize);
        $readEnd    = min($fileSize, $offset + $chunkSize);
        $readLength = $readEnd - $readStart;

        // Read chunk
        $chunk = $fileIO->readFileChunk($filePath, $readLength, $readStart);

        // Adjust offset to be relative to chunk start
        $relativeOffset = $offset - $readStart;

        // Search for object start in chunk
        $objStartMarker  = (string) $objectNumber . ' ' . $generation . ' obj';
        $objStartInChunk = strpos($chunk, $objStartMarker, max(0, $relativeOffset - 100));

        if ($objStartInChunk === false) {
            // Object not found in chunk, try reading larger area
            $largerChunkSize = 262144; // 256KB
            $readStart       = max(0, $offset - $largerChunkSize);
            $readEnd         = min($fileSize, $offset + $largerChunkSize);
            $readLength      = $readEnd - $readStart;
            $chunk           = $fileIO->readFileChunk($filePath, $readLength, $readStart);
            $relativeOffset  = $offset - $readStart;
            $objStartInChunk = strpos($chunk, $objStartMarker, max(0, $relativeOffset - 100));

            if ($objStartInChunk === false) {
                return null;
            }
        }

        // Find endobj
        $objEndInChunk = strpos($chunk, 'endobj', $objStartInChunk);

        if ($objEndInChunk === false) {
            // endobj might be outside chunk, read more
            $remainingStart    = $readStart + $objStartInChunk;
            $remainingLength   = min($fileSize - $remainingStart, 1048576); // Read up to 1MB more
            $remainingChunk    = $fileIO->readFileChunk($filePath, $remainingLength, $remainingStart);
            $objEndInRemaining = strpos($remainingChunk, 'endobj');

            if ($objEndInRemaining === false) {
                return null;
            }

            // Combine chunks
            $objStartAbsolute = $readStart + $objStartInChunk;
            $objEndAbsolute   = $remainingStart + $objEndInRemaining + 6;
            $objectLength     = $objEndAbsolute - $objStartAbsolute;

            return $fileIO->readFileChunk($filePath, $objectLength, $objStartAbsolute);
        }

        // Extract object content from chunk
        $objStartAbsolute = $readStart + $objStartInChunk;
        $objEndAbsolute   = $readStart + $objEndInChunk + 6;
        $objectLength     = $objEndAbsolute - $objStartAbsolute;

        // If object fits in chunk, return it
        if ($objStartAbsolute >= $readStart && $objEndAbsolute <= $readEnd) {
            return substr($chunk, $objStartInChunk, $objEndInChunk - $objStartInChunk + 6);
        }

        // Otherwise read exact content
        return $fileIO->readFileChunk($filePath, $objectLength, $objStartAbsolute);
    }

    /**
     * Parse xref stream from file at given offset, handling Prev references recursively.
     *
     * @param string          $filePath         PDF file path
     * @param FileIOInterface $fileIO           File IO interface
     * @param int             $fileSize         File size in bytes
     * @param int             $xrefPos          Xref stream object position
     * @param string          $absolutePath     Absolute file path for logging
     * @param array<int>      $visitedPositions Track visited positions to prevent cycles
     *
     * @return PDFXrefTable Merged xref table including all Prev references
     */
    private function parseXrefStreamFromFile(
        string $filePath,
        FileIOInterface $fileIO,
        int $fileSize,
        int $xrefPos,
        string $absolutePath,
        array &$visitedPositions = []
    ): PDFXrefTable {
        // Check for cycles
        if (in_array($xrefPos, $visitedPositions, true)) {
            $this->logger->warning('Circular Prev reference detected in xref stream, breaking cycle', [
                'file_path'         => $absolutePath,
                'xref_position'     => $xrefPos,
                'visited_positions' => $visitedPositions,
            ]);

            // Return empty table to break the cycle
            return new PDFXrefTable;
        }

        $visitedPositions[] = $xrefPos;
        // Read chunk to find object number
        $readSize = min(100, $fileSize - $xrefPos);
        $chunk    = $fileIO->readFileChunk($filePath, $readSize, $xrefPos);

        // Extract object number from "obj" marker
        if (preg_match('/(\d+)\s+(\d+)\s+obj/', $chunk, $matches)) {
            $objectNumber = (int) $matches[1];
            $generation   = (int) $matches[2];
        } else {
            // Fallback: try to find object number
            $objectNumber = 0;
            $generation   = 0;
        }

        // Read object content
        $objectContent = $this->extractObjectContentFromFile($filePath, $fileIO, $xrefPos, $objectNumber, $generation);

        if ($objectContent === null) {
            throw new FpdfException('Unable to extract xref stream object');
        }

        // Parse the stream object
        $stream = $this->parseObject($objectContent, $objectNumber);

        if (!($stream instanceof PDFStream)) {
            throw new FpdfException('Xref stream object is not a stream');
        }

        // Get decoded stream data
        $streamData = $stream->getDecodedData();
        $dict       = $stream->getDictionary();

        // Convert dictionary to format expected by XrefStreamParser
        $streamDict = $this->convertDictionaryToArray($dict);

        // Parse the stream
        $xrefTable        = new PDFXrefTable;
        $xrefStreamParser = new XrefStreamParser;
        $prevOffset       = $xrefStreamParser->parseStream($streamData, $streamDict, $xrefTable);

        // If there's a Prev reference, validate it, parse it recursively and merge
        if ($prevOffset !== null && $prevOffset > 0) {
            if ($prevOffset < $fileSize) {
                $this->logger->debug('Found Prev reference in xref stream, parsing previous xref', [
                    'file_path'   => $absolutePath,
                    'prev_offset' => $prevOffset,
                ]);

                // Check if previous is traditional or stream
                $readSize          = min(100, $fileSize - $prevOffset);
                $prevChunk         = $fileIO->readFileChunk($filePath, $readSize, $prevOffset);
                $isPrevTraditional = str_starts_with($prevChunk, 'xref');

                if ($isPrevTraditional) {
                    $prevXrefTable = $this->parseXrefTableFromFile($filePath, $fileIO, $fileSize, $prevOffset, $absolutePath, $visitedPositions);
                } else {
                    $prevXrefTable = $this->parseXrefStreamFromFile($filePath, $fileIO, $fileSize, $prevOffset, $absolutePath, $visitedPositions);
                }

                // Merge entries: merge previous into current, so newer entries (current) override older ones (prev)
                $xrefTable->mergeEntries($prevXrefTable);
            } else {
                $this->logger->warning('Prev reference outside file bounds, ignoring', ['file_path' => $absolutePath, 'prev_offset' => $prevOffset]);
            }
        }

        return $xrefTable;
    }

    /**
     * Parse xref stream from content at given offset, handling Prev references recursively.
     *
     * @param string     $content          PDF content
     * @param int        $xrefPos          Xref stream object position
     * @param array<int> $visitedPositions Track visited positions to prevent cycles
     *
     * @return PDFXrefTable Merged xref table including all Prev references
     */
    private function parseXrefStream(string $content, int $xrefPos, array &$visitedPositions = []): PDFXrefTable
    {
        // Check for cycles
        if (in_array($xrefPos, $visitedPositions, true)) {
            $this->logger->warning('Circular Prev reference detected in xref stream, breaking cycle', [
                'xref_position'     => $xrefPos,
                'visited_positions' => $visitedPositions,
            ]);

            // Return empty table to break the cycle
            return new PDFXrefTable;
        }

        $visitedPositions[] = $xrefPos;
        // Extract object number from content at position
        $chunk = substr($content, $xrefPos, 100);

        if (preg_match('/(\d+)\s+(\d+)\s+obj/', $chunk, $matches)) {
            $objectNumber = (int) $matches[1];
            $generation   = (int) $matches[2];
        } else {
            // Fallback
            $objectNumber = 0;
            $generation   = 0;
        }

        // Extract object content
        $objectContent = $this->extractObjectContent($content, $xrefPos, $objectNumber, $generation);

        if ($objectContent === null) {
            throw new FpdfException('Unable to extract xref stream object');
        }

        // Parse the stream object
        $stream = $this->parseObject($objectContent, $objectNumber);

        if (!($stream instanceof PDFStream)) {
            throw new FpdfException('Xref stream object is not a stream');
        }

        // Get decoded stream data
        $streamData = $stream->getDecodedData();
        $dict       = $stream->getDictionary();

        // Convert dictionary to format expected by XrefStreamParser
        $streamDict = $this->convertDictionaryToArray($dict);

        // Parse the stream
        $xrefTable        = new PDFXrefTable;
        $xrefStreamParser = new XrefStreamParser;
        $prevOffset       = $xrefStreamParser->parseStream($streamData, $streamDict, $xrefTable);

        // If there's a Prev reference, parse it recursively and merge
        if ($prevOffset !== null && $prevOffset > 0) {
            $this->logger->debug('Found Prev reference in xref stream, parsing previous xref', [
                'prev_offset' => $prevOffset,
            ]);

            // Check if previous is traditional or stream
            $isPrevTraditional = strpos($content, 'xref', $prevOffset) === $prevOffset;

            if ($isPrevTraditional) {
                $prevXrefTable = $this->parseXrefTable($content, $prevOffset, $visitedPositions);
            } else {
                $prevXrefTable = $this->parseXrefStream($content, $prevOffset, $visitedPositions);
            }

            // Merge entries: merge previous into current, so newer entries (current) override older ones (prev)
            $xrefTable->mergeEntries($prevXrefTable);
        }

        return $xrefTable;
    }

    /**
     * Convert PDFDictionary to array format expected by XrefStreamParser.
     *
     * @param PDFDictionary $dict Dictionary to convert
     *
     * @return array Dictionary in array format
     */
    private function convertDictionaryToArray(PDFDictionary $dict): array
    {
        $result  = [];
        $entries = $dict->getAllEntries();

        foreach ($entries as $key => $value) {
            // Keys in getAllEntries() don't have leading '/', so add it
            $keyWithSlash = '/' . ltrim($key, '/');
            $result[]     = ['/', $keyWithSlash];

            if ($value instanceof PDFName) {
                $result[] = ['/', '/' . $value->getName()];
            } elseif ($value instanceof PDFNumber) {
                $result[] = ['numeric', (string) $value->getValue()];
            } elseif ($value instanceof PDFReference) {
                $result[] = ['objref', (string) $value->getObjectNumber() . '_' . (string) $value->getGenerationNumber()];
            } elseif ($value instanceof PDFArray) {
                $arrayItems = [];

                foreach ($value->getAll() as $item) {
                    if ($item instanceof PDFNumber) {
                        $arrayItems[] = ['numeric', (string) $item->getValue()];
                    } elseif ($item instanceof PDFName) {
                        $arrayItems[] = ['/', '/' . $item->getName()];
                    }
                }
                $result[] = ['[', $arrayItems];
            } elseif ($value instanceof PDFDictionary) {
                $nestedDict    = [];
                $nestedEntries = $value->getAllEntries();

                foreach ($nestedEntries as $nestedKey => $nestedValue) {
                    $nestedKeyWithSlash = '/' . ltrim($nestedKey, '/');
                    $nestedDict[]       = ['/', $nestedKeyWithSlash];

                    if ($nestedValue instanceof PDFNumber) {
                        $nestedDict[] = ['numeric', (string) $nestedValue->getValue()];
                    } elseif ($nestedValue instanceof PDFName) {
                        $nestedDict[] = ['/', '/' . $nestedValue->getName()];
                    }
                }
                $result[] = ['<<', $nestedDict];
            } else {
                $result[] = ['null', null];
            }
        }

        return $result;
    }

    /**
     * Parse trailer information from xref stream dictionary.
     *
     * @param PDFDictionary $dict     Stream dictionary
     * @param PDFDocument   $document Document to update
     */
    private function parseTrailerFromStreamDict(PDFDictionary $dict, PDFDocument $document): void
    {
        $sizeEntry = $dict->getEntry('/Size');

        if ($sizeEntry instanceof PDFNumber) {
            $document->getTrailer()->setSize((int) $sizeEntry->getValue());
        }

        $rootEntry = $dict->getEntry('/Root');

        if ($rootEntry instanceof PDFReference) {
            $document->getTrailer()->setRoot($rootEntry);
        }

        $infoEntry = $dict->getEntry('/Info');

        if ($infoEntry instanceof PDFReference) {
            $document->getTrailer()->setInfo($infoEntry);
        }

        $encryptEntry = $dict->getEntry('/Encrypt');

        if ($encryptEntry instanceof PDFReference) {
            $document->getTrailer()->setEncrypt($encryptEntry);
        }
    }
}
