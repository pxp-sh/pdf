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
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function basename;
use function count;
use function dirname;
use function fclose;
use function fwrite;
use function gc_collect_cycles;
use function gzinflate;
use function gzuncompress;
use function implode;
use function in_array;
use function is_dir;
use function ltrim;
use function memory_get_usage;
use function microtime;
use function mkdir;
use function preg_match_all;
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
        // Use smaller interval (25) with streaming for more aggressive cleanup
        // Reduced from 50 to 25 to prevent memory accumulation
        $cacheClearInterval = 25;

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
                $memBefore = memory_get_usage(true);
                $this->logger->debug('Clearing cache for memory management', [
                    'page_number'          => $pageNum,
                    'cache_clear_interval' => $cacheClearInterval,
                    'memory_before_mb'     => round($memBefore / 1024 / 1024, 2),
                ]);

                $this->document->getObjectRegistry()->clearCache();

                // Force garbage collection to free memory
                gc_collect_cycles();

                $memAfter = memory_get_usage(true);
                $this->logger->debug('Cache cleared', [
                    'memory_freed_mb' => round(($memBefore - $memAfter) / 1024 / 1024, 2),
                    'memory_after_mb' => round($memAfter / 1024 / 1024, 2),
                ]);
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

        // Aggressive memory cleanup
        unset($pageNode, $pageDict, $bytesWritten);

        // Clear document object cache for this page
        $this->document->getObjectRegistry()->clearCache();

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
        $allEncoded     = true; // Track if all streams are already encoded

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

        // Combine all content streams - prefer using encoded data if available
        $combinedContent = '';
        $hasCompression  = false;

        // Log content stream analysis
        $this->logger->debug('Content stream analysis started', [
            'total_streams' => count($contentStreams),
        ]);

        // Check if we can use encoded data (all streams have same compression)
        $firstHasCompression = false;

        if (!empty($contentStreams)) {
            $firstHasCompression = $contentStreams[0]->hasFilter('FlateDecode');
            $allSameCompression  = true;

            foreach ($contentStreams as $stream) {
                if ($stream->hasFilter('FlateDecode') !== $firstHasCompression) {
                    $allSameCompression = false;

                    break;
                }
            }

            // Log individual stream sizes before processing
            $totalEncodedSize = 0;
            $totalDecodedSize = 0;

            foreach ($contentStreams as $index => $stream) {
                $encodedSize = strlen($stream->getEncodedData());
                $decodedSize = strlen($stream->getDecodedData());
                $totalEncodedSize += $encodedSize;
                $totalDecodedSize += $decodedSize;

                $this->logger->debug("Content stream #{$index}", [
                    'encoded_size'      => $encodedSize,
                    'decoded_size'      => $decodedSize,
                    'has_flate'         => $stream->hasFilter('FlateDecode'),
                    'compression_ratio' => $encodedSize > 0 ? round($decodedSize / $encodedSize, 2) : 0,
                ]);
            }

            $this->logger->debug('Content streams total sizes', [
                'total_encoded'         => $totalEncodedSize,
                'total_decoded'         => $totalDecodedSize,
                'all_same_compression'  => $allSameCompression,
                'first_has_compression' => $firstHasCompression,
            ]);

            // If all have same compression, we must decode to combine
            // Otherwise copy the first stream's data only to save memory
            if (count($contentStreams) === 1) {
                // Single stream - can use encoded data directly
                $combinedContent = $contentStreams[0]->getEncodedData();
                $hasCompression  = $firstHasCompression;
                $allEncoded      = true;

                $this->logger->debug('Single content stream - using encoded data', [
                    'encoded_size'    => strlen($combinedContent),
                    'has_compression' => $hasCompression,
                ]);
            } else {
                // Multiple streams - must decode and combine
                $this->logger->debug('Multiple content streams - decoding and combining', [
                    'stream_count' => count($contentStreams),
                ]);

                foreach ($contentStreams as $index => $stream) {
                    $decodedData = $stream->getDecodedData();
                    $beforeSize  = strlen($combinedContent);
                    $combinedContent .= $decodedData;
                    $afterSize = strlen($combinedContent);

                    $this->logger->debug("Concatenated stream #{$index}", [
                        'bytes_added'  => strlen($decodedData),
                        'total_before' => $beforeSize,
                        'total_after'  => $afterSize,
                    ]);

                    unset($decodedData, $contentStreams[$index]);
                }
                $hasCompression = $firstHasCompression;
                $allEncoded     = false;

                $this->logger->debug('Content streams combined', [
                    'final_size'      => strlen($combinedContent),
                    'will_recompress' => $hasCompression,
                ]);
            }
        }

        unset($contentStreams);

        if (!$allEncoded && strlen($combinedContent) > 1024 * 1024) {
            gc_collect_cycles();
        }

        $this->logger->debug('After content processing', [
            'content_length'   => strlen($combinedContent),
            'content_is_empty' => empty($combinedContent),
            'all_encoded'      => $allEncoded,
        ]);

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

        // Analyze content to find used resources (for filtering)
        $usedResources = null;

        if (!empty($combinedContent)) {
            // We need decoded content for analysis
            $contentForAnalysis = $combinedContent;

            $this->logger->debug('Content analysis setup', [
                'content_size'    => strlen($combinedContent),
                'all_encoded'     => $allEncoded,
                'has_compression' => $hasCompression,
            ]);

            // If content is still encoded (single stream case with allEncoded=true),
            // we need to decode it for analysis
            if ($allEncoded && $hasCompression) {
                // Content is encoded, need to decode for analysis
                $this->logger->debug('Attempting to decode content for analysis');

                // Use gzuncompress first
                $contentForAnalysis = @gzuncompress($combinedContent);

                if ($contentForAnalysis === false) {
                    // Try gzinflate as fallback
                    $this->logger->debug('gzuncompress failed, trying gzinflate');
                    $contentForAnalysis = @gzinflate($combinedContent);

                    if ($contentForAnalysis === false) {
                        // Decoding failed, analyze what we have
                        $this->logger->warning('Failed to decode content for resource analysis');
                        $contentForAnalysis = $combinedContent;
                    } else {
                        $this->logger->debug('Successfully decoded with gzinflate', [
                            'decoded_size' => strlen($contentForAnalysis),
                        ]);
                    }
                } else {
                    $this->logger->debug('Successfully decoded with gzuncompress', [
                        'decoded_size' => strlen($contentForAnalysis),
                    ]);
                }
            }

            $usedResources = $this->analyzeUsedResources($contentForAnalysis);

            $this->logger->info('Before expansion check', [
                'resourcesDict_is_null' => $resourcesDict === null,
                'xobjects_empty'        => empty($usedResources['xobjects']),
                'xobjects_count'        => count($usedResources['xobjects'] ?? []),
            ]);

            // Expand XObject dependencies recursively (Form XObjects may reference other XObjects)
            if ($resourcesDict !== null && !empty($usedResources['xobjects'])) {
                $expandedResources = $this->expandXObjectDependencies(
                    $usedResources['xobjects'],
                    $resourcesDict,
                );

                // Merge expanded resources with original
                $usedResources['xobjects'] = $expandedResources['xobjects'];
                $usedResources['fonts']    = array_unique(array_merge(
                    $usedResources['fonts'],
                    $expandedResources['fonts'],
                ));

                $this->logger->info('Resource expansion result', [
                    'has_nested_resources_key'   => isset($expandedResources['has_nested_resources']),
                    'has_nested_resources_value' => $expandedResources['has_nested_resources'] ?? 'NOT_SET',
                ]);

                // CRITICAL FIX: If any Form XObjects have nested /Resources dictionaries,
                // disable filtering entirely because nested XObjects won't be in the main
                // page /XObject dictionary and would be incorrectly filtered out.
                if (isset($expandedResources['has_nested_resources']) && $expandedResources['has_nested_resources']) {
                    $this->logger->info('✓✓✓ Disabling resource filtering - Form XObjects have nested /Resources', [
                        'expanded_xobjects' => count($expandedResources['xobjects']),
                    ]);
                    $usedResources = null;
                }
            }
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

        // Object 2: Resources - copy and resolve all references with filtering
        $nextObjectNumber = 6; // Start after catalog (5)

        if ($resourcesDict !== null) {
            $newResourcesDict = $this->copyResourcesWithReferences($resourcesDict, $newDoc, $nextObjectNumber, $usedResources);
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

            // If content is already encoded, use it directly
            if ($allEncoded && $hasCompression) {
                $newStream = new PDFStream($streamDict, '', true);
                $newStream->setEncodedData($combinedContent);
                $newStream->addFilter('FlateDecode');
            } else {
                // Content is decoded, create stream normally
                $newStream = new PDFStream($streamDict, $combinedContent, false);

                if ($hasCompression) {
                    $newStream->addFilter('FlateDecode');
                }
            }
            $newDoc->addObject($newStream, 4);
        } else {
            // Empty stream
            $emptyStream = new PDFStream(new PDFDictionary, '');
            $newDoc->addObject($emptyStream, 4);
        }

        // Clear content immediately
        unset($combinedContent);

        // Object 5: Catalog
        $catalog = new CatalogDictionary;
        $catalog->setPages(1);
        $catalogNode = $newDoc->addObject($catalog, 5);
        $newDoc->setRoot($catalogNode);

        // Serialize
        return $newDoc->serialize();
    }

    /**
     * Analyze content stream to identify which resources are actually used.
     *
     * This method parses the PDF content stream operators to find references
     * to fonts (Tf operator) and XObjects (Do operator).
     *
     * @param string $contentData The decoded content stream data
     *
     * @return array{fonts: array<string>, xobjects: array<string>} Array of used resource names
     */
    private function analyzeUsedResources(string $contentData): array
    {
        $used = ['fonts' => [], 'xobjects' => []];

        // Match font references: /F1 Tf, /F2 12 Tf, etc.
        // Pattern: /FontName size Tf
        if (preg_match_all('/\/([A-Za-z][A-Za-z0-9_]*)\s+[\d.]+\s+Tf/', $contentData, $matches)) {
            $used['fonts'] = array_values(array_unique($matches[1]));
        }

        // Match XObject (including images and form XObjects) references: /TPL0 Do, /I1 Do, etc.
        // Pattern: /XObjectName Do
        if (preg_match_all('/\/([A-Za-z][A-Za-z0-9_]*)\s+Do/', $contentData, $matches)) {
            $used['xobjects'] = array_values(array_unique($matches[1]));
        }

        $this->logger->debug('Analyzed content stream for resource usage', [
            'fonts_found'    => count($used['fonts']),
            'xobjects_found' => count($used['xobjects']),
            'fonts'          => implode(', ', array_slice($used['fonts'], 0, 20)),
            'xobjects'       => implode(', ', array_slice($used['xobjects'], 0, 20)),
        ]);

        return $used;
    }

    /**
     * Recursively expand XObject dependencies.
     *
     * Form XObjects can contain references to other XObjects (images, other forms, etc.).
     * This method recursively analyzes Form XObjects to find all transitive Form XObject dependencies.
     *
     * IMPORTANT: Nested resources WITHIN a Form XObject (like images in TPL529's resources)
     * are automatically copied when the Form XObject is copied. We only need to track
     * which Form XObjects transitively reference other Form XObjects.
     *
     * @param array<string> $xobjects      Initial list of XObject names
     * @param PDFDictionary $resourcesDict Original resources dictionary
     * @param int           $maxDepth      Maximum recursion depth (prevent infinite loops)
     *
     * @return array{fonts: array<string>, xobjects: array<string>, has_nested_resources: bool} Expanded resource dependencies
     */
    private function expandXObjectDependencies(array $xobjects, PDFDictionary $resourcesDict, int $maxDepth = 10): array
    {
        $allFonts           = [];
        $allXObjects        = $xobjects;
        $processed          = [];
        $toProcess          = $xobjects;
        $depth              = 0;
        $hasNestedResources = false;

        $xobjEntry = $resourcesDict->getEntry('/XObject');

        if (!$xobjEntry instanceof PDFDictionary) {
            // No XObjects in resources
            return ['fonts' => [], 'xobjects' => $xobjects, 'has_nested_resources' => false];
        }

        while (!empty($toProcess) && $depth < $maxDepth) {
            $currentBatch = $toProcess;
            $toProcess    = [];

            foreach ($currentBatch as $xobjName) {
                if (isset($processed[$xobjName])) {
                    continue; // Already processed
                }
                $processed[$xobjName] = true;

                // Get the XObject from main resources dictionary
                $xobjRef = $xobjEntry->getEntry('/' . $xobjName);

                if (!$xobjRef instanceof PDFReference) {
                    continue; // Not found in main resources
                }

                // Get the XObject itself
                $xobjNode = $this->document->getObject($xobjRef->getObjectNumber());

                if ($xobjNode === null) {
                    continue;
                }

                $xobjValue = $xobjNode->getValue();

                if (!$xobjValue instanceof PDFStream) {
                    continue;
                }

                // Check if it's a Form XObject
                $xobjDict = $xobjValue->getDictionary();
                $subtype  = $xobjDict->getEntry('/Subtype');

                if (!$subtype instanceof PDFName || $subtype->getName() !== 'Form') {
                    // Not a Form XObject (probably an Image), no nested processing needed
                    continue;
                }

                // It's a Form XObject - analyze its content stream for OTHER Form XObjects
                $formContent     = $xobjValue->getDecodedData();
                $nestedResources = $this->analyzeUsedResources($formContent);

                // IMPORTANT: Only add fonts from nested analysis
                // XObjects referenced in the Form's content might be:
                // 1. In the Form's own /Resources - automatically copied with the Form
                // 2. In the main resources - we need to ensure they're in our filter
                foreach ($nestedResources['fonts'] as $font) {
                    if (!in_array($font, $allFonts, true)) {
                        $allFonts[] = $font;
                    }
                }

                // For XObjects referenced in the Form's content:
                // Only process those that exist in the MAIN resources (not nested)
                foreach ($nestedResources['xobjects'] as $nestedXObj) {
                    // Check if this XObject exists in the main resources dictionary
                    $nestedXObjRef = $xobjEntry->getEntry('/' . $nestedXObj);

                    if ($nestedXObjRef instanceof PDFReference) {
                        // It exists in main resources - add it to our list
                        if (!in_array($nestedXObj, $allXObjects, true)) {
                            $allXObjects[] = $nestedXObj;

                            if (!isset($processed[$nestedXObj])) {
                                $toProcess[] = $nestedXObj;
                            }
                        }
                    }
                    // If it doesn't exist in main resources, it's in the Form's nested resources
                    // and will be automatically copied when we copy the Form XObject
                }

                // CRITICAL FIX: Also check Form XObject's nested /Resources dictionary
                // Form XObjects can have their own /Resources with additional XObjects
                $formResources = $xobjDict->getEntry('/Resources');

                if ($formResources !== null) {
                    $hasNestedResources = true; // Flag that we found nested resources

                    // Resolve if reference
                    if ($formResources instanceof PDFReference) {
                        $formResourcesNode = $this->document->getObject($formResources->getObjectNumber());

                        if ($formResourcesNode !== null) {
                            $formResources = $formResourcesNode->getValue();
                        }
                    }

                    if ($formResources instanceof PDFDictionary) {
                        // Check for XObjects in Form's nested resources
                        $formXObjects = $formResources->getEntry('/XObject');

                        if ($formXObjects !== null) {
                            // Resolve if reference
                            if ($formXObjects instanceof PDFReference) {
                                $formXObjectsNode = $this->document->getObject($formXObjects->getObjectNumber());

                                if ($formXObjectsNode !== null) {
                                    $formXObjects = $formXObjectsNode->getValue();
                                }
                            }

                            if ($formXObjects instanceof PDFDictionary) {
                                // Add all XObjects from Form's nested resources to main list
                                foreach ($formXObjects->getAllEntries() as $formXObjName => $formXObjRef) {
                                    $formXObjShortName = ltrim($formXObjName, '/');
                                    // Check if exists in main resources
                                    $mainXObjRef = $xobjEntry->getEntry($formXObjName);

                                    if ($mainXObjRef instanceof PDFReference) {
                                        // Exists in main resources - add to list
                                        if (!in_array($formXObjShortName, $allXObjects, true)) {
                                            $allXObjects[] = $formXObjShortName;

                                            if (!isset($processed[$formXObjShortName])) {
                                                $toProcess[] = $formXObjShortName;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $depth++;
        }

        if ($depth >= $maxDepth) {
            $this->logger->warning('XObject dependency expansion hit max depth', [
                'max_depth'      => $maxDepth,
                'xobjects_found' => count($allXObjects),
            ]);
        }

        $this->logger->debug('Expanded XObject dependencies recursively', [
            'initial_xobjects'     => count($xobjects),
            'expanded_xobjects'    => count($allXObjects),
            'fonts_from_forms'     => count($allFonts),
            'recursion_depth'      => $depth,
            'has_nested_resources' => $hasNestedResources,
        ]);

        return [
            'fonts'                => $allFonts,
            'xobjects'             => $allXObjects,
            'has_nested_resources' => $hasNestedResources,
        ];
    }

    /**
     * Copy resources dictionary and resolve all references to objects in the new document.
     *
     * Optionally filters resources to only include those actually used in the content stream.
     *
     * @param PDFDictionary                     $resourcesDict    Original resources dictionary
     * @param PDFDocument                       $newDoc           New document to add objects to
     * @param int                               $nextObjectNumber Next available object number
     * @param null|array<string, array<string>> $usedResources    Optional filter for resources (from analyzeUsedResources)
     *
     * @return ResourcesDictionary New resources dictionary with resolved references
     */
    private function copyResourcesWithReferences(
        PDFDictionary $resourcesDict,
        PDFDocument $newDoc,
        int &$nextObjectNumber,
        ?array $usedResources = null
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

        // Copy Fonts (with optional filtering)
        $fonts = $resourcesDict->getEntry('/Font');

        if ($fonts instanceof PDFDictionary) {
            $newFonts     = new PDFDictionary;
            $fontsSkipped = 0;

            foreach ($fonts->getAllEntries() as $fontName => $fontRef) {
                // Filter: Skip fonts not used in content stream
                if ($usedResources !== null && !empty($usedResources['fonts'])) {
                    $fontShortName = ltrim($fontName, '/');

                    if (!in_array($fontShortName, $usedResources['fonts'], true)) {
                        $fontsSkipped++;

                        continue;
                    }
                }

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

            if ($fontsSkipped > 0) {
                $this->logger->debug('Filtered unused fonts', [
                    'skipped' => $fontsSkipped,
                    'kept'    => count($newFonts->getAllEntries()),
                ]);
            }
        }

        // Copy XObjects (images and templates) with optional filtering
        $xObjects = $resourcesDict->getEntry('/XObject');

        if ($xObjects instanceof PDFDictionary) {
            $newXObjects     = new PDFDictionary;
            $xobjectsSkipped = 0;

            foreach ($xObjects->getAllEntries() as $xObjectName => $xObjectRef) {
                // Filter: Skip XObjects not used in content stream
                if ($usedResources !== null && !empty($usedResources['xobjects'])) {
                    $xobjectShortName = ltrim($xObjectName, '/');

                    if (!in_array($xobjectShortName, $usedResources['xobjects'], true)) {
                        $xobjectsSkipped++;

                        continue;
                    }
                }

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

            // Add XObject dictionary if not empty or if no filtering applied
            if (count($newXObjects->getAllEntries()) > 0 || $usedResources === null) {
                $newResources->addEntry('/XObject', $newXObjects);
            }

            if ($xobjectsSkipped > 0) {
                $this->logger->debug('Filtered unused XObjects', [
                    'skipped' => $xobjectsSkipped,
                    'kept'    => count($newXObjects->getAllEntries()),
                ]);
            }
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
            // CRITICAL: Copy stream without decoding/re-encoding to save memory
            // Get the stream dictionary and recursively copy it (preserves /Subtype, /Filter, and all metadata)
            $streamDict = $obj->getDictionary();
            $copiedDict = $this->copyObjectWithReferences($streamDict, $newDoc, $nextObjectNumber, $objectMap, $copyingObjects);

            if (!$copiedDict instanceof PDFDictionary) {
                $copiedDict = new PDFDictionary;
            }

            // Create new stream with ALREADY ENCODED data to avoid re-encoding
            // Pass true for $dataIsEncoded to indicate data is pre-encoded
            $newStream = new PDFStream($copiedDict, '', true);

            // Set encoded data directly without decoding first (saves massive memory)
            $encodedData = $obj->getEncodedData();
            $newStream->setEncodedData($encodedData);

            // Clear encoded data immediately to free memory
            unset($encodedData);

            // NOTE: We don't need to add filters manually - they're already in the copied dictionary
            // and PDFStream's constructor reads them via updateFiltersFromDictionary()

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
     * CRITICAL FIX: For complex PDFs with many resources (fonts, XObjects),
     * we must write ALL resource objects to the stream BEFORE writing the
     * Resources dictionary that references them.
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

        // CRITICAL FIX: Copy resources and write all resource objects FIRST
        // Object 2 will be Resources dictionary, objects 6+ will be resource objects (fonts, XObjects, etc.)
        $nextObjectNumber = 6; // Start after catalog (5)
        $newResourcesDict = null;

        if ($pageData['resourcesDict'] !== null) {
            // Analyze content to find used resources (for filtering)
            $usedResources = null;

            if (!empty($pageData['content'])) {
                $usedResources = $this->analyzeUsedResources($pageData['content']);

                // Expand XObject dependencies recursively (Form XObjects may reference other XObjects)
                if (!empty($usedResources['xobjects'])) {
                    $expandedResources = $this->expandXObjectDependencies(
                        $usedResources['xobjects'],
                        $pageData['resourcesDict'],
                    );

                    // Merge expanded resources with original
                    $usedResources['xobjects'] = $expandedResources['xobjects'];
                    $usedResources['fonts']    = array_unique(array_merge(
                        $usedResources['fonts'],
                        $expandedResources['fonts'],
                    ));

                    // CRITICAL FIX: If any Form XObjects have nested /Resources dictionaries,
                    // disable filtering entirely because nested XObjects won't be in the main
                    // page /XObject dictionary and would be incorrectly filtered out.
                    if (isset($expandedResources['has_nested_resources']) && $expandedResources['has_nested_resources']) {
                        $this->logger->info('Disabling resource filtering - Form XObjects have nested /Resources', [
                            'expanded_xobjects' => count($expandedResources['xobjects']),
                        ]);
                        $usedResources = null;
                    }
                }
            }

            // Copy resources with filtering - this adds objects to $newDoc starting from $nextObjectNumber
            $newResourcesDict = $this->copyResourcesWithReferences(
                $pageData['resourcesDict'],
                $newDoc,
                $nextObjectNumber,
                $usedResources,  // Pass used resources for filtering
            );

            // Now write all the resource objects (fonts, XObjects, etc.) that were added to $newDoc
            // These are objects numbered from 6 to ($nextObjectNumber - 1)
            $registry = $newDoc->getObjectRegistry();

            for ($objNum = 6; $objNum < $nextObjectNumber; $objNum++) {
                $objNode = $registry->get($objNum);

                if ($objNode !== null) {
                    $objectOffsets[$objNum] = $currentOffset;
                    $obj                    = $objNode->getValue();
                    $currentOffset += $this->writeObjectToStream($handle, $obj, $objNum);
                }
            }
        } else {
            $newResourcesDict = new ResourcesDictionary;
        }

        // NOW write Object 2: Resources dictionary (which references objects 6+)
        $objectOffsets[2] = $currentOffset;
        $currentOffset += $this->writeObjectToStream($handle, $newResourcesDict, 2);

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

        // Free content immediately and force GC if content was large
        $contentSize = strlen($pageData['content'] ?? '');
        unset($pageData['content']);

        if ($contentSize > 512 * 1024) { // > 512KB
            gc_collect_cycles();
        }

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

        // Combine content streams with memory-efficient concatenation
        $combinedContent = '';

        foreach ($contentStreams as $index => $stream) {
            $combinedContent .= $stream->getDecodedData();
            // Free each stream immediately after processing to reduce memory
            unset($contentStreams[$index]);
        }
        // Final cleanup
        unset($contentStreams);

        // Force garbage collection after processing large content
        if (strlen($combinedContent) > 1024 * 1024) { // > 1MB
            gc_collect_cycles();
        }

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

        $result = [
            'mediaBox'        => $mediaBox,
            'pageHasMediaBox' => $pageHasMediaBox,
            'content'         => $combinedContent,
            'resourcesDict'   => $resourcesDict,
            'hasCompression'  => $hasCompression,
        ];

        // Clear heavy variables immediately
        unset($pageDict, $mediaBoxEntry, $contentsRef, $resourcesRef);

        return $result;
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
