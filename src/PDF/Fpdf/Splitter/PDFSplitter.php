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
namespace PXP\PDF\Fpdf\Splitter;

use function array_map;
use function basename;
use function count;
use function dirname;
use function fclose;
use function fwrite;
use function gc_collect_cycles;
use function in_array;
use function is_dir;
use function microtime;
use function mkdir;
use function realpath;
use function round;
use function rtrim;
use function sprintf;
use function strlen;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use PXP\PDF\Fpdf\Cache\NullCache;
use PXP\PDF\Fpdf\Event\NullDispatcher;
use PXP\PDF\Fpdf\Exception\FpdfException;
use PXP\PDF\Fpdf\IO\FileIOInterface;
use PXP\PDF\Fpdf\Log\NullLogger;
use PXP\PDF\Fpdf\Object\Array\KidsArray;
use PXP\PDF\Fpdf\Object\Array\MediaBoxArray;
use PXP\PDF\Fpdf\Object\Base\PDFArray;
use PXP\PDF\Fpdf\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Object\Base\PDFName;
use PXP\PDF\Fpdf\Object\Base\PDFNumber;
use PXP\PDF\Fpdf\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Object\Dictionary\CatalogDictionary;
use PXP\PDF\Fpdf\Object\Dictionary\PageDictionary;
use PXP\PDF\Fpdf\Object\Dictionary\ResourcesDictionary;
use PXP\PDF\Fpdf\Object\Parser\PDFParser;
use PXP\PDF\Fpdf\Object\PDFObjectInterface;
use PXP\PDF\Fpdf\Stream\PDFStream;
use PXP\PDF\Fpdf\Tree\PDFDocument;
use PXP\PDF\Fpdf\Tree\PDFObjectNode;
use PXP\PDF\Fpdf\Tree\PDFObjectRegistry;

final class PDFSplitter
{
    private PDFDocument $document;
    private LoggerInterface $logger;
    private EventDispatcherInterface $dispatcher;
    private CacheItemPoolInterface $cache;

    public function __construct(
        string $pdfFilePath,
        private FileIOInterface $fileIO,
        ?LoggerInterface $logger = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?CacheItemPoolInterface $cache = null,
    ) {
        $this->logger     = $logger ?? new NullLogger;
        $this->dispatcher = $dispatcher ?? new NullDispatcher;
        $this->cache      = $cache ?? new NullCache;

        $absolutePath = realpath($pdfFilePath) ?: $pdfFilePath;
        $this->logger->info('PDF split operation started', [
            'file_path' => $absolutePath,
        ]);

        // Use file-based parsing to avoid loading entire file into memory
        $parser         = new PDFParser($this->logger, $this->cache);
        $this->document = $parser->parseDocumentFromFile($pdfFilePath, $this->fileIO);
    }

    /**
     * Split PDF into individual page files.
     *
     * @param string      $outputDir       Directory where split PDFs will be saved
     * @param null|string $filenamePattern Pattern for output filenames (use %d for page number, default: "page_%d.pdf")
     *
     * @return array<string> Array of generated file paths
     */
    public function splitByPage(string $outputDir, ?string $filenamePattern = null): array
    {
        $absoluteOutputDir = realpath($outputDir) ?: $outputDir;
        $startTime         = microtime(true);

        $this->logger->debug('Split operation started', [
            'output_dir'       => $absoluteOutputDir,
            'filename_pattern' => $filenamePattern,
        ]);

        if (!is_dir($outputDir)) {
            $this->logger->debug('Creating output directory', [
                'output_dir' => $absoluteOutputDir,
            ]);

            if (!mkdir($outputDir, 0o777, true) && !is_dir($outputDir)) {
                $this->logger->error('Could not create output directory', [
                    'output_dir' => $absoluteOutputDir,
                ]);

                throw new FpdfException('Could not create output directory: ' . $outputDir);
            }
        }

        if ($filenamePattern === null) {
            $filenamePattern = 'page_%d.pdf';
        }

        $outputFiles = [];
        $totalPages  = $this->getPageCount();

        $this->logger->info('PDF page count determined', [
            'total_pages' => $totalPages,
        ]);

        // Clear cache periodically to manage memory for large PDFs
        // Use smaller interval (50) with streaming for more aggressive cleanup
        $cacheClearInterval = 50;

        for ($pageNum = 1; $pageNum <= $totalPages; $pageNum++) {
            $filename   = sprintf($filenamePattern, $pageNum);
            $outputPath = rtrim($outputDir, '/\\') . '/' . $filename;

            $this->logger->debug('Extracting page', [
                'page_number' => $pageNum,
                'output_path' => $outputPath,
            ]);

            // extractPage() now uses streaming by default
            $this->extractPage($pageNum, $outputPath);
            $outputFiles[] = $outputPath;

            // Clear cache periodically to free memory
            if ($pageNum % $cacheClearInterval === 0) {
                $this->logger->debug('Clearing cache for memory management', [
                    'page_number'          => $pageNum,
                    'cache_clear_interval' => $cacheClearInterval,
                ]);
                $this->document->getObjectRegistry()->clearCache();
                // Force garbage collection to free memory
                gc_collect_cycles();
            }
        }

        // Final cleanup
        gc_collect_cycles();

        $duration = (microtime(true) - $startTime) * 1000;
        $this->logger->info('PDF split operation completed', [
            'total_pages'  => $totalPages,
            'output_files' => count($outputFiles),
            'duration_ms'  => round($duration, 2),
        ]);

        return $outputFiles;
    }

    /**
     * Extract a single page to a new PDF file.
     *
     * @param int    $pageNumber Page number (1-based)
     * @param string $outputPath Path where the single-page PDF will be saved
     */
    public function extractPage(int $pageNumber, string $outputPath): void
    {
        // Use streaming extraction by default for memory efficiency
        $this->extractPageStreaming($pageNumber, $outputPath);
    }

    /**
     * Extract a single page using streaming writes (memory-optimized).
     *
     * This method writes the PDF directly to disk without building
     * the entire PDF in memory first. Memory usage: O(1 page).
     *
     * @param int    $pageNumber Page number (1-based)
     * @param string $outputPath Path where the single-page PDF will be saved
     */
    public function extractPageStreaming(int $pageNumber, string $outputPath): void
    {
        $absoluteOutputPath = realpath(dirname($outputPath)) ? '/' . basename($outputPath) : $outputPath;

        $this->logger->debug('Extracting single page (streaming)', [
            'page_number' => $pageNumber,
            'output_path' => $absoluteOutputPath,
        ]);

        $pageNode = $this->document->getPage($pageNumber);

        if ($pageNode === null) {
            $this->logger->error('Invalid page number', [
                'page_number' => $pageNumber,
            ]);

            throw new FpdfException('Invalid page number: ' . $pageNumber);
        }

        $pageDict = $pageNode->getValue();

        if (!$pageDict instanceof PDFDictionary) {
            $this->logger->error('Page object is not a dictionary', [
                'page_number' => $pageNumber,
            ]);

            throw new FpdfException('Page object is not a dictionary');
        }

        // Open output file for streaming writes
        $handle       = $this->fileIO->openWriteStream($outputPath);
        $bytesWritten = 0;

        try {
            // Stream write the single-page PDF
            $bytesWritten = $this->buildSinglePagePdfStreaming($pageNode, $handle);

            $this->logger->debug('Page extraction completed (streaming)', [
                'page_number'   => $pageNumber,
                'output_path'   => $absoluteOutputPath,
                'bytes_written' => $bytesWritten,
            ]);
        } finally {
            fclose($handle);
        }

        // Free memory immediately
        unset($pageNode, $pageDict);
        gc_collect_cycles();
    }

    /**
     * Legacy batch extraction method (kept for compatibility).
     *
     * This builds the entire PDF in memory before writing.
     * Use extractPageStreaming() for better memory efficiency.
     *
     * @param int    $pageNumber Page number (1-based)
     * @param string $outputPath Path where the single-page PDF will be saved
     */
    public function extractPageBatch(int $pageNumber, string $outputPath): void
    {
        $absoluteOutputPath = realpath(dirname($outputPath)) ? '/' . basename($outputPath) : $outputPath;

        $this->logger->debug('Extracting single page (batch)', [
            'page_number' => $pageNumber,
            'output_path' => $absoluteOutputPath,
        ]);

        $pageNode = $this->document->getPage($pageNumber);

        if ($pageNode === null) {
            $this->logger->error('Invalid page number', [
                'page_number' => $pageNumber,
            ]);

            throw new FpdfException('Invalid page number: ' . $pageNumber);
        }

        $pageDict = $pageNode->getValue();

        if (!$pageDict instanceof PDFDictionary) {
            $this->logger->error('Page object is not a dictionary', [
                'page_number' => $pageNumber,
            ]);

            throw new FpdfException('Page object is not a dictionary');
        }

        $this->logger->debug('Building single-page PDF', [
            'page_number' => $pageNumber,
        ]);

        // Build new PDF using tree structure
        $newPdf = $this->buildSinglePagePdf($pageNode);

        $this->logger->debug('Writing single-page PDF to file', [
            'page_number' => $pageNumber,
            'output_path' => $absoluteOutputPath,
            'pdf_size'    => strlen($newPdf),
        ]);

        // Write to file using streamable approach
        $this->fileIO->writeFile($outputPath, $newPdf);

        $this->logger->debug('Page extraction completed', [
            'page_number' => $pageNumber,
            'output_path' => $absoluteOutputPath,
        ]);
    }

    /**
     * Get total number of pages in the PDF.
     */
    public function getPageCount(): int
    {
        $this->logger->debug('Determining page count');

        try {
            // Use getAllPages() to count actual pages (handles nested Pages trees)
            $allPages = $this->document->getAllPages(true);
            $count    = count($allPages);
            $this->logger->debug('Page count determined', [
                'page_count' => $count,
            ]);

            return $count;
        } catch (FpdfException $e) {
            // Fallback: Try to read /Count field from Pages dictionary
            $this->logger->warning('Failed to get pages recursively, falling back to /Count field', [
                'error' => $e->getMessage(),
            ]);

            $pagesNode = $this->document->getPages();

            if ($pagesNode === null) {
                $this->logger->warning('Pages node not found');

                return 0;
            }

            $pagesDict = $pagesNode->getValue();

            if (!$pagesDict instanceof PDFDictionary) {
                $this->logger->warning('Pages dictionary not found');

                return 0;
            }

            $countEntry = $pagesDict->getEntry('/Count');

            if ($countEntry instanceof PDFNumber) {
                $count = (int) $countEntry->getValue();
                $this->logger->debug('Page count determined from /Count field', [
                    'page_count' => $count,
                ]);

                return $count;
            }

            // Last fallback: count direct kids (non-recursive)
            $kids = $pagesDict->getEntry('/Kids');

            if ($kids instanceof PDFArray) {
                $count = $kids->count();
                $this->logger->debug('Page count determined from Kids array count', [
                    'page_count' => $count,
                ]);

                return $count;
            }

            $this->logger->warning('Could not determine page count');

            return 0;
        }
    }

    /**
     * Build a single-page PDF from a page node.
     */
    private function buildSinglePagePdf(PDFObjectNode $pageNode): string
    {
        $pageDict = $pageNode->getValue();

        if (!$pageDict instanceof PDFDictionary) {
            throw new FpdfException('Page object is not a dictionary');
        }

        // Get MediaBox - check page first, then parent Pages dictionary
        $mediaBoxEntry   = $pageDict->getEntry('/MediaBox');
        $pageHasMediaBox = false;
        $mediaBox        = [0.0, 0.0, 612.0, 792.0]; // Default

        if ($mediaBoxEntry instanceof MediaBoxArray) {
            $mediaBox        = $mediaBoxEntry->getValues();
            $pageHasMediaBox = true;
        } elseif ($mediaBoxEntry instanceof PDFArray) {
            $values = [];

            foreach ($mediaBoxEntry->getAll() as $item) {
                if ($item instanceof PDFNumber) {
                    $values[] = (float) $item->getValue();
                }
            }

            if (count($values) >= 4) {
                $mediaBox        = [$values[0], $values[1], $values[2], $values[3]];
                $pageHasMediaBox = true;
            }
        }

        // If page doesn't have MediaBox, check parent Pages dictionary
        if (!$pageHasMediaBox) {
            $parentRef = $pageDict->getEntry('/Parent');

            if ($parentRef instanceof PDFReference) {
                $parentNode = $this->document->getObject($parentRef->getObjectNumber());

                if ($parentNode !== null) {
                    $parentDict = $parentNode->getValue();

                    if ($parentDict instanceof PDFDictionary) {
                        $parentMediaBoxEntry = $parentDict->getEntry('/MediaBox');

                        if ($parentMediaBoxEntry instanceof MediaBoxArray) {
                            $mediaBox = $parentMediaBoxEntry->getValues();
                        } elseif ($parentMediaBoxEntry instanceof PDFArray) {
                            $values = [];

                            foreach ($parentMediaBoxEntry->getAll() as $item) {
                                if ($item instanceof PDFNumber) {
                                    $values[] = (float) $item->getValue();
                                }
                            }

                            if (count($values) >= 4) {
                                $mediaBox = [$values[0], $values[1], $values[2], $values[3]];
                            }
                        }
                    }
                }
            }
        }

        // Get Contents - can be a single reference or an array of references
        $contentsRef    = $pageDict->getEntry('/Contents');
        $contentStreams = [];

        if ($contentsRef instanceof PDFReference) {
            // Single content stream
            $contentNode = $this->document->getObject($contentsRef->getObjectNumber());

            if ($contentNode !== null) {
                $contentObj = $contentNode->getValue();

                if ($contentObj instanceof PDFStream) {
                    $contentStreams[] = $contentObj;
                }
            }
        } elseif ($contentsRef instanceof PDFArray) {
            // Multiple content streams
            foreach ($contentsRef->getAll() as $item) {
                if ($item instanceof PDFReference) {
                    $contentNode = $this->document->getObject($item->getObjectNumber());

                    if ($contentNode !== null) {
                        $contentObj = $contentNode->getValue();

                        if ($contentObj instanceof PDFStream) {
                            $contentStreams[] = $contentObj;
                        }
                    }
                }
            }
        }

        // Combine all content streams into one
        $combinedContent = '';

        foreach ($contentStreams as $stream) {
            $combinedContent .= $stream->getDecodedData();
        }

        // Get Resources - can be a reference or inline dictionary
        $resourcesRef  = $pageDict->getEntry('/Resources');
        $resourcesDict = null;

        if ($resourcesRef instanceof PDFReference) {
            $resourcesNode = $this->document->getObject($resourcesRef->getObjectNumber());

            if ($resourcesNode !== null) {
                $resourcesObj = $resourcesNode->getValue();

                if ($resourcesObj instanceof PDFDictionary) {
                    $resourcesDict = $resourcesObj;
                }
            }
        } elseif ($resourcesRef instanceof PDFDictionary) {
            // Resources is inline
            $resourcesDict = $resourcesRef;
        }

        // Create new document with cache-enabled registry
        $registry = new PDFObjectRegistry(null, null, null, null, null, $this->cache, $this->logger);
        $newDoc   = new PDFDocument('1.3', $registry);

        // Object 1: Pages dictionary
        $pagesDict = new PDFDictionary;
        $pagesDict->addEntry('/Type', new PDFName('Pages'));
        $kids = new KidsArray;
        $kids->addPage(3); // Page will be object 3
        $pagesDict->addEntry('/Kids', $kids);
        $pagesDict->addEntry('/Count', new PDFNumber(1));
        $pagesDict->addEntry('/MediaBox', new MediaBoxArray($mediaBox));
        $pagesNode = $newDoc->addObject($pagesDict, 1);

        // Object 2: Resources - copy and resolve all references
        $nextObjectNumber = 6; // Start after catalog (5)

        if ($resourcesDict !== null) {
            $newResourcesDict = $this->copyResourcesWithReferences($resourcesDict, $newDoc, $nextObjectNumber);
            $newDoc->addObject($newResourcesDict, 2);
        } else {
            $emptyResources = new ResourcesDictionary;
            $newDoc->addObject($emptyResources, 2);
        }

        // Object 3: Page
        $newPageDict = new PageDictionary;
        $newPageDict->setParent($pagesNode);

        // Only set MediaBox on page if original page had it (otherwise inherit from parent)
        if ($pageHasMediaBox) {
            $newPageDict->setMediaBox($mediaBox);
        }
        $newPageDict->setResources(2);
        $newPageDict->setContents(4); // Content stream will be object 4
        $newDoc->addObject($newPageDict, 3);

        // Object 4: Content stream
        if (!empty($combinedContent)) {
            // Create new stream with combined content
            $streamDict = new PDFDictionary;
            // Use FlateDecode if original had compression, otherwise no filter
            $hasCompression = false;

            if (!empty($contentStreams)) {
                $firstStream    = $contentStreams[0];
                $hasCompression = $firstStream->hasFilter('FlateDecode');
            }

            $newStream = new PDFStream($streamDict, $combinedContent, false);

            if ($hasCompression) {
                $newStream->addFilter('FlateDecode');
            }
            $newDoc->addObject($newStream, 4);
        } else {
            // Empty stream
            $emptyStream = new PDFStream(new PDFDictionary, '');
            $newDoc->addObject($emptyStream, 4);
        }

        // Object 5: Catalog
        $catalog = new CatalogDictionary;
        $catalog->setPages(1);
        $catalogNode = $newDoc->addObject($catalog, 5);
        $newDoc->setRoot($catalogNode);

        // Serialize
        return $newDoc->serialize();
    }

    /**
     * Copy resources dictionary and resolve all references to objects in the new document.
     *
     * @param PDFDictionary $resourcesDict    Original resources dictionary
     * @param PDFDocument   $newDoc           New document to add objects to
     * @param int           $nextObjectNumber Next available object number
     *
     * @return ResourcesDictionary New resources dictionary with resolved references
     */
    private function copyResourcesWithReferences(
        PDFDictionary $resourcesDict,
        PDFDocument $newDoc,
        int &$nextObjectNumber
    ): ResourcesDictionary {
        $newResources = new ResourcesDictionary;
        $objectMap    = []; // Track copied objects to avoid duplicates

        // Copy ProcSet if present
        $procSet = $resourcesDict->getEntry('/ProcSet');

        if ($procSet !== null) {
            $newResources->setProcSet(
                $procSet instanceof PDFArray
                ? array_map(
                    static fn ($item) => $item instanceof PDFName ? $item->getName() : (string) $item,
                    $procSet->getAll(),
                )
                : ['PDF', 'Text', 'ImageB', 'ImageC', 'ImageI'],
            );
        }

        // Copy Fonts
        $fonts = $resourcesDict->getEntry('/Font');

        if ($fonts instanceof PDFDictionary) {
            $newFonts = new PDFDictionary;

            foreach ($fonts->getAllEntries() as $fontName => $fontRef) {
                $copyingObjects = [];

                if ($fontRef instanceof PDFReference) {
                    $fontNode = $this->document->getObject($fontRef->getObjectNumber());

                    if ($fontNode !== null) {
                        $fontObj = $fontNode->getValue();
                        // Copy font object and all its referenced objects recursively
                        $copiedFont  = $this->copyObjectWithReferences($fontObj, $newDoc, $nextObjectNumber, $objectMap, $copyingObjects);
                        $newFontNode = $newDoc->addObject($copiedFont, $nextObjectNumber);
                        $newFonts->addEntry($fontName, new PDFReference($nextObjectNumber));
                        $nextObjectNumber++;
                    }
                } elseif ($fontRef instanceof PDFDictionary) {
                    // Inline font dictionary - copy with references resolved
                    $copiedFont  = $this->copyObjectWithReferences($fontRef, $newDoc, $nextObjectNumber, $objectMap, $copyingObjects);
                    $newFontNode = $newDoc->addObject($copiedFont, $nextObjectNumber);
                    $newFonts->addEntry($fontName, new PDFReference($nextObjectNumber));
                    $nextObjectNumber++;
                }
            }

            if (count($newFonts->getAllEntries()) > 0) {
                $newResources->addEntry('/Font', $newFonts);
            }
        }

        // Copy XObjects (images) - even if empty
        $xObjects = $resourcesDict->getEntry('/XObject');

        if ($xObjects instanceof PDFDictionary) {
            $newXObjects = new PDFDictionary;

            foreach ($xObjects->getAllEntries() as $xObjectName => $xObjectRef) {
                $copyingObjects = [];

                if ($xObjectRef instanceof PDFReference) {
                    $xObjectNode = $this->document->getObject($xObjectRef->getObjectNumber());

                    if ($xObjectNode !== null) {
                        $xObjectObj = $xObjectNode->getValue();
                        // Copy XObject and all its referenced objects recursively
                        $copiedXObject  = $this->copyObjectWithReferences($xObjectObj, $newDoc, $nextObjectNumber, $objectMap, $copyingObjects);
                        $newXObjectNode = $newDoc->addObject($copiedXObject, $nextObjectNumber);
                        $newXObjects->addEntry($xObjectName, new PDFReference($nextObjectNumber));
                        $nextObjectNumber++;
                    }
                } elseif ($xObjectRef instanceof PDFStream) {
                    // Inline XObject stream - copy with references resolved
                    $copiedXObject  = $this->copyObjectWithReferences($xObjectRef, $newDoc, $nextObjectNumber, $objectMap, $copyingObjects);
                    $newXObjectNode = $newDoc->addObject($copiedXObject, $nextObjectNumber);
                    $newXObjects->addEntry($xObjectName, new PDFReference($nextObjectNumber));
                    $nextObjectNumber++;
                }
            }
            // Always add XObject dictionary, even if empty (to match original structure)
            $newResources->addEntry('/XObject', $newXObjects);
        }

        // Copy any other resource types (ColorSpace, Pattern, Shading, ExtGState, etc.)
        foreach ($resourcesDict->getAllEntries() as $key => $value) {
            if (in_array($key, ['ProcSet', 'Font', 'XObject'], true)) {
                continue; // Already handled
            }

            if ($value instanceof PDFReference) {
                // Copy referenced object
                $refNode = $this->document->getObject($value->getObjectNumber());

                if ($refNode !== null) {
                    $refObj     = $refNode->getValue();
                    $newRefNode = $newDoc->addObject($refObj, $nextObjectNumber);
                    $newResources->addEntry($key, new PDFReference($nextObjectNumber));
                    $nextObjectNumber++;
                }
            } elseif ($value instanceof PDFDictionary || $value instanceof PDFArray) {
                // Copy inline dictionary or array (may contain references that need resolution)
                $copyingObjects = [];
                $copiedValue    = $this->copyObjectWithReferences($value, $newDoc, $nextObjectNumber, $objectMap, $copyingObjects);
                $newResources->addEntry($key, $copiedValue);
            } else {
                // Copy primitive value as-is
                $newResources->addEntry($key, $value);
            }
        }

        return $newResources;
    }

    /**
     * Recursively copy an object and resolve all references.
     * Uses a map to track already-copied objects to avoid duplicates.
     *
     * @param PDFObjectInterface $obj               Object to copy
     * @param PDFDocument        $newDoc            New document
     * @param int                &$nextObjectNumber Next available object number (passed by reference)
     * @param array<int, int>    $objectMap         Map of old object numbers to new object numbers
     * @param array<int, true>   $copyingObjects    Set of object IDs currently being copied (to detect cycles)
     *
     * @return PDFObjectInterface Copied object
     */
    private function copyObjectWithReferences(
        PDFObjectInterface $obj,
        PDFDocument $newDoc,
        int &$nextObjectNumber,
        array &$objectMap = [],
        array &$copyingObjects = []
    ): PDFObjectInterface {
        if ($obj instanceof PDFReference) {
            $oldObjNum = $obj->getObjectNumber();

            // Check if we've already copied this object
            if (isset($objectMap[$oldObjNum])) {
                return new PDFReference($objectMap[$oldObjNum]);
            }

            // Check if we're currently copying this object (circular reference detected)
            if (isset($copyingObjects[$oldObjNum])) {
                // We're in a cycle - the object number should already be in the map
                // Return a reference to it
                if (isset($objectMap[$oldObjNum])) {
                    return new PDFReference($objectMap[$oldObjNum]);
                }
                // If not in map yet, this shouldn't happen, but handle gracefully
                $newObjNum             = $nextObjectNumber;
                $objectMap[$oldObjNum] = $newObjNum;
                $nextObjectNumber++;

                return new PDFReference($newObjNum);
            }

            $refNode = $this->document->getObject($oldObjNum);

            if ($refNode !== null) {
                $refObj = $refNode->getValue();

                // Reserve object number and add to map FIRST (before marking as copying)
                // This ensures that if we encounter a circular reference, the object number
                // will already be in the map
                $newObjNum             = $nextObjectNumber;
                $objectMap[$oldObjNum] = $newObjNum;
                $nextObjectNumber++;

                // Mark as currently being copied (after adding to map)
                $copyingObjects[$oldObjNum] = true;

                // Recursively copy the referenced object (now safe from circular refs)
                $copiedRefObj = $this->copyObjectWithReferences($refObj, $newDoc, $nextObjectNumber, $objectMap, $copyingObjects);

                // Add the copied object to the new document
                $newRefNode = $newDoc->addObject($copiedRefObj, $newObjNum);

                // Remove from copying set
                unset($copyingObjects[$oldObjNum]);

                return new PDFReference($newObjNum);
            }

            return $obj; // Return original if not found
        }

        if ($obj instanceof PDFDictionary) {
            $newDict = new PDFDictionary;

            foreach ($obj->getAllEntries() as $key => $value) {
                if ($value instanceof PDFObjectInterface) {
                    $newDict->addEntry($key, $this->copyObjectWithReferences($value, $newDoc, $nextObjectNumber, $objectMap, $copyingObjects));
                } else {
                    $newDict->addEntry($key, $value);
                }
            }

            return $newDict;
        }

        if ($obj instanceof PDFArray) {
            $newArray = new PDFArray;

            foreach ($obj->getAll() as $item) {
                if ($item instanceof PDFObjectInterface) {
                    $newArray->add($this->copyObjectWithReferences($item, $newDoc, $nextObjectNumber, $objectMap, $copyingObjects));
                } else {
                    $newArray->add($item);
                }
            }

            return $newArray;
        }

        if ($obj instanceof PDFStream) {
            // Copy stream dictionary and data
            $streamDict = $obj->getDictionary();
            $streamData = $obj->getDecodedData();
            $copiedDict = $this->copyObjectWithReferences($streamDict, $newDoc, $nextObjectNumber, $objectMap, $copyingObjects);

            if (!$copiedDict instanceof PDFDictionary) {
                $copiedDict = new PDFDictionary;
            }

            // Create new stream with copied dictionary
            $newStream = new PDFStream($copiedDict, $streamData, false);

            // Copy all filters
            foreach ($obj->getFilters() as $filter) {
                $newStream->addFilter($filter);
            }

            return $newStream;
        }

        // For other types (primitives), return as-is
        return $obj;
    }

    /**
     * Build a single-page PDF using streaming writes (memory-optimized).
     *
     * This method writes PDF objects directly to the output stream
     * without assembling the entire PDF in memory.
     *
     * @return int Total bytes written
     */
    private function buildSinglePagePdfStreaming(PDFObjectNode $pageNode, $handle): int
    {
        $pageDict = $pageNode->getValue();

        if (!$pageDict instanceof PDFDictionary) {
            throw new FpdfException('Page object is not a dictionary');
        }

        // Extract page data
        $pageData = $this->extractPageDataForStreaming($pageNode);

        // Create new document with cache-enabled registry
        $registry = new PDFObjectRegistry(null, null, null, null, null, $this->cache, $this->logger);
        $newDoc   = new PDFDocument('1.3', $registry);

        // Track byte offsets for xref table
        $objectOffsets = [];
        $currentOffset = 0;

        // Write PDF header
        $header = "%PDF-1.3\n";
        fwrite($handle, $header);
        $currentOffset += strlen($header);

        // Write binary comment (ensures PDF is treated as binary)
        $binaryComment = "%\xE2\xE3\xCF\xD3\n";
        fwrite($handle, $binaryComment);
        $currentOffset += strlen($binaryComment);

        // Object 1: Pages dictionary
        $objectOffsets[1] = $currentOffset;
        $pagesDict        = new PDFDictionary;
        $pagesDict->addEntry('/Type', new PDFName('Pages'));
        $kids = new KidsArray;
        $kids->addPage(3); // Page will be object 3
        $pagesDict->addEntry('/Kids', $kids);
        $pagesDict->addEntry('/Count', new PDFNumber(1));
        $pagesDict->addEntry('/MediaBox', new MediaBoxArray($pageData['mediaBox']));
        $pagesNode = $newDoc->addObject($pagesDict, 1);
        $currentOffset += $this->writeObjectToStream($handle, $pagesDict, 1);

        // Object 2: Resources
        $objectOffsets[2] = $currentOffset;
        $nextObjectNumber = 6; // Start after catalog (5)

        if ($pageData['resourcesDict'] !== null) {
            $newResourcesDict = $this->copyResourcesWithReferences(
                $pageData['resourcesDict'],
                $newDoc,
                $nextObjectNumber,
            );
            $currentOffset += $this->writeObjectToStream($handle, $newResourcesDict, 2);
        } else {
            $emptyResources = new ResourcesDictionary;
            $currentOffset += $this->writeObjectToStream($handle, $emptyResources, 2);
        }

        // Object 3: Page dictionary
        $objectOffsets[3] = $currentOffset;
        $newPageDict      = new PageDictionary;
        $newPageDict->setParent($pagesNode);

        if ($pageData['pageHasMediaBox']) {
            $newPageDict->setMediaBox($pageData['mediaBox']);
        }
        $newPageDict->setResources(2);
        $newPageDict->setContents(4);
        $currentOffset += $this->writeObjectToStream($handle, $newPageDict, 3);

        // Free page data immediately
        unset($pageData['resourcesDict']);

        // Object 4: Content stream
        $objectOffsets[4] = $currentOffset;

        if (!empty($pageData['content'])) {
            $streamDict = new PDFDictionary;
            $newStream  = new PDFStream($streamDict, $pageData['content'], false);

            if ($pageData['hasCompression']) {
                $newStream->addFilter('FlateDecode');
            }
            $currentOffset += $this->writeObjectToStream($handle, $newStream, 4);
        } else {
            $emptyStream = new PDFStream(new PDFDictionary, '');
            $currentOffset += $this->writeObjectToStream($handle, $emptyStream, 4);
        }

        // Free content immediately
        unset($pageData['content']);

        // Object 5: Catalog
        $objectOffsets[5] = $currentOffset;
        $catalog          = new CatalogDictionary;
        $catalog->setPages(1);
        $currentOffset += $this->writeObjectToStream($handle, $catalog, 5);

        // Write xref table
        $xrefOffset = $currentOffset;
        $xref       = "xref\n";
        $xref .= '0 ' . (count($objectOffsets) + 1) . "\n";
        $xref .= "0000000000 65535 f \n";

        for ($i = 1; $i <= count($objectOffsets); $i++) {
            $xref .= sprintf("%010d 00000 n \n", $objectOffsets[$i]);
        }

        fwrite($handle, $xref);
        $currentOffset += strlen($xref);

        // Write trailer
        $trailer = "trailer\n";
        $trailer .= '<< /Size ' . (count($objectOffsets) + 1) . " /Root 5 0 R >>\n";
        $trailer .= "startxref\n";
        $trailer .= $xrefOffset . "\n";
        $trailer .= "%%EOF\n";

        fwrite($handle, $trailer);
        $currentOffset += strlen($trailer);

        return $currentOffset;
    }

    /**
     * Extract minimal page data needed for streaming write.
     *
     * @return array{mediaBox: array<float>, pageHasMediaBox: bool, content: string, resourcesDict: null|PDFDictionary, hasCompression: bool}
     */
    private function extractPageDataForStreaming(PDFObjectNode $pageNode): array
    {
        $pageDict = $pageNode->getValue();

        if (!$pageDict instanceof PDFDictionary) {
            throw new FpdfException('Page object is not a dictionary');
        }

        // Get MediaBox
        $mediaBoxEntry   = $pageDict->getEntry('/MediaBox');
        $pageHasMediaBox = false;
        $mediaBox        = [0.0, 0.0, 612.0, 792.0]; // Default

        if ($mediaBoxEntry instanceof MediaBoxArray) {
            $mediaBox        = $mediaBoxEntry->getValues();
            $pageHasMediaBox = true;
        } elseif ($mediaBoxEntry instanceof PDFArray) {
            $values = [];

            foreach ($mediaBoxEntry->getAll() as $item) {
                if ($item instanceof PDFNumber) {
                    $values[] = (float) $item->getValue();
                }
            }

            if (count($values) >= 4) {
                $mediaBox        = [$values[0], $values[1], $values[2], $values[3]];
                $pageHasMediaBox = true;
            }
        }

        // If page doesn't have MediaBox, check parent
        if (!$pageHasMediaBox) {
            $parentRef = $pageDict->getEntry('/Parent');

            if ($parentRef instanceof PDFReference) {
                $parentNode = $this->document->getObject($parentRef->getObjectNumber());

                if ($parentNode !== null) {
                    $parentDict = $parentNode->getValue();

                    if ($parentDict instanceof PDFDictionary) {
                        $parentMediaBoxEntry = $parentDict->getEntry('/MediaBox');

                        if ($parentMediaBoxEntry instanceof MediaBoxArray) {
                            $mediaBox = $parentMediaBoxEntry->getValues();
                        } elseif ($parentMediaBoxEntry instanceof PDFArray) {
                            $values = [];

                            foreach ($parentMediaBoxEntry->getAll() as $item) {
                                if ($item instanceof PDFNumber) {
                                    $values[] = (float) $item->getValue();
                                }
                            }

                            if (count($values) >= 4) {
                                $mediaBox = [$values[0], $values[1], $values[2], $values[3]];
                            }
                        }
                    }
                }
            }
        }

        // Get Contents
        $contentsRef    = $pageDict->getEntry('/Contents');
        $contentStreams = [];
        $hasCompression = false;

        if ($contentsRef instanceof PDFReference) {
            $contentNode = $this->document->getObject($contentsRef->getObjectNumber());

            if ($contentNode !== null) {
                $contentObj = $contentNode->getValue();

                if ($contentObj instanceof PDFStream) {
                    $contentStreams[] = $contentObj;
                    $hasCompression   = $contentObj->hasFilter('FlateDecode');
                }
            }
        } elseif ($contentsRef instanceof PDFArray) {
            foreach ($contentsRef->getAll() as $item) {
                if ($item instanceof PDFReference) {
                    $contentNode = $this->document->getObject($item->getObjectNumber());

                    if ($contentNode !== null) {
                        $contentObj = $contentNode->getValue();

                        if ($contentObj instanceof PDFStream) {
                            $contentStreams[] = $contentObj;

                            if ($contentObj->hasFilter('FlateDecode')) {
                                $hasCompression = true;
                            }
                        }
                    }
                }
            }
        }

        // Combine content streams
        $combinedContent = '';

        foreach ($contentStreams as $stream) {
            $combinedContent .= $stream->getDecodedData();
        }
        // Free streams immediately
        unset($contentStreams);

        // Get Resources
        $resourcesRef  = $pageDict->getEntry('/Resources');
        $resourcesDict = null;

        if ($resourcesRef instanceof PDFReference) {
            $resourcesNode = $this->document->getObject($resourcesRef->getObjectNumber());

            if ($resourcesNode !== null) {
                $resourcesObj = $resourcesNode->getValue();

                if ($resourcesObj instanceof PDFDictionary) {
                    $resourcesDict = $resourcesObj;
                }
            }
        } elseif ($resourcesRef instanceof PDFDictionary) {
            $resourcesDict = $resourcesRef;
        }

        return [
            'mediaBox'        => $mediaBox,
            'pageHasMediaBox' => $pageHasMediaBox,
            'content'         => $combinedContent,
            'resourcesDict'   => $resourcesDict,
            'hasCompression'  => $hasCompression,
        ];
    }

    /**
     * Write a single PDF object to stream.
     *
     * @return int Bytes written
     */
    private function writeObjectToStream($handle, PDFObjectInterface $obj, int $objNum): int
    {
        // Create PDFObjectNode for proper serialization
        $node   = new PDFObjectNode($objNum, $obj);
        $objStr = (string) $node . "\n";

        fwrite($handle, $objStr);

        return strlen($objStr);
    }
}
