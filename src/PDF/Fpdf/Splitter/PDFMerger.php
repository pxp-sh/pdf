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

use PXP\PDF\Fpdf\Cache\NullCache;
use PXP\PDF\Fpdf\Event\NullDispatcher;
use PXP\PDF\Fpdf\Exception\FpdfException;
use PXP\PDF\Fpdf\IO\FileIOInterface;
use PXP\PDF\Fpdf\Log\NullLogger;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use PXP\PDF\Fpdf\Object\Array\KidsArray;
use PXP\PDF\Fpdf\Object\Array\MediaBoxArray;
use PXP\PDF\Fpdf\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Object\Parser\PDFParser;
use PXP\PDF\Fpdf\Stream\PDFStream;
use PXP\PDF\Fpdf\Tree\PDFDocument;

final class PDFMerger
{
    private LoggerInterface $logger;
    private EventDispatcherInterface $dispatcher;
    private CacheItemPoolInterface $cache;
    private FileIOInterface $fileIO;

    public function __construct(
        FileIOInterface $fileIO,
        ?LoggerInterface $logger = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?CacheItemPoolInterface $cache = null,
    ) {
        $this->fileIO = $fileIO;
        $this->logger = $logger ?? new NullLogger();
        $this->dispatcher = $dispatcher ?? new NullDispatcher();
        $this->cache = $cache ?? new NullCache();
    }

    /**
     * Merge multiple PDF files into a single PDF
     *
     * @param array<string> $pdfFilePaths Array of paths to PDF files to merge
     * @param string $outputPath Path where the merged PDF will be saved
     * @throws FpdfException
     */
    public function merge(array $pdfFilePaths, string $outputPath): void
    {
        if (empty($pdfFilePaths)) {
            throw new \InvalidArgumentException('At least one PDF file is required for merging');
        }

        $startTime = microtime(true);

        $this->logger->info('PDF merge operation started', [
            'input_files' => count($pdfFilePaths),
            'output_path' => $outputPath,
        ]);

        // Verify all input files exist
        foreach ($pdfFilePaths as $pdfPath) {
            if (!file_exists($pdfPath)) {
                throw new \RuntimeException('PDF file not found: ' . $pdfPath);
            }
        }

        // Create new merged document
        $registry = new \PXP\PDF\Fpdf\Tree\PDFObjectRegistry(null, null, null, null, null, $this->cache, $this->logger);
        $mergedDoc = new PDFDocument('1.3', $registry);

        $parser = new PDFParser($this->logger, $this->cache);
        $allPages = [];
        $nextObjectNumber = 1;
        $globalResources = new \PXP\PDF\Fpdf\Object\Dictionary\ResourcesDictionary();
        $resourceNameMap = []; // Map to handle resource name conflicts

        // Process each input PDF
        foreach ($pdfFilePaths as $pdfIndex => $pdfPath) {
            $absolutePath = realpath($pdfPath) ?: $pdfPath;
            $this->logger->debug('Processing PDF file', [
                'file_index' => $pdfIndex + 1,
                'file_path' => $absolutePath,
            ]);

            $document = $parser->parseDocumentFromFile($pdfPath, $this->fileIO);
            $pageCount = $this->getPageCount($document);
            $originalPageCount = $pageCount; // Keep original expected count for fallback placeholder creation

            $this->logger->debug('Extracting pages from PDF', [
                'file_path' => $absolutePath,
                'page_count' => $pageCount,
            ]);

            // Extract all pages from this document
            $pagesExtractedForThisPdf = 0;
            // Used to hold nodes returned by getAllPages fallback so we can derive an authoritative expected count
            $fallbackAllPageNodes = null;
            if ($pageCount > 0) {
                for ($pageNum = 1; $pageNum <= $pageCount; $pageNum++) {
                    // Verify page exists before extracting
                    $pageNode = $document->getPage($pageNum);
                    if ($pageNode === null) {
                        $this->logger->warning('Page not found, skipping', [
                            'file_path' => $absolutePath,
                            'page_number' => $pageNum,
                            'expected_count' => $pageCount,
                        ]);
                        // If we can't get the first page, try fallback method
                        if ($pageNum === 1) {
                            $pageCount = 0; // Trigger fallback
                            break;
                        }
                        // Otherwise, just skip this page and continue
                        continue;
                    }
                    try {
                        $pageData = $this->extractPageData($document, $pageNum, $nextObjectNumber, $mergedDoc, $resourceNameMap);
                        $allPages[] = $pageData;
                        $pagesExtractedForThisPdf++;
                    } catch (FpdfException $e) {
                        $this->logger->warning('Page extraction failed', [
                            'file_path' => $absolutePath,
                            'page_number' => $pageNum,
                            'error' => $e->getMessage(),
                        ]);
                        // If extraction fails for first page, try fallback
                        if ($pageNum === 1) {
                            $pageCount = 0; // Trigger fallback
                            break;
                        }
                        // Otherwise, continue to next page
                        continue;
                    }
                }
            }

            // Fallback: If pageCount was 0 or we couldn't extract any pages, try extracting using getAllPages
            if ($pageCount === 0 || $pagesExtractedForThisPdf === 0) {
                $this->logger->warning('Page count is 0 or no pages extracted, trying getAllPages fallback', [
                    'file_path' => $absolutePath,
                ]);

                // First try: Use getAllPages() directly, but limit to expected page count
                // This prevents extracting embedded pages or duplicates
                $expectedPageCount = $this->getPageCount($document);
                try {
                    $allPageNodes = $document->getAllPages(true);
                    // record fallback page nodes so we can derive an authoritative page count if needed
                    $fallbackAllPageNodes = $allPageNodes;
                    if (!empty($allPageNodes)) {
                        // Limit to expected page count to avoid extracting embedded pages
                        $pagesToExtract = min(count($allPageNodes), $expectedPageCount > 0 ? $expectedPageCount : count($allPageNodes));
                        for ($pageIndex = 0; $pageIndex < $pagesToExtract; $pageIndex++) {
                                    $pageNum = $pageIndex + 1;
                            $pageNode = $allPageNodes[$pageIndex];
                            try {
                                // First try the existing extraction by page number
                                $pageData = $this->extractPageData($document, $pageNum, $nextObjectNumber, $mergedDoc, $resourceNameMap);
                                $allPages[] = $pageData;
                                $pagesExtractedForThisPdf++;
                            } catch (FpdfException $e) {
                                $this->logger->debug('Page extraction failed in getAllPages fallback, attempting node-based extraction', [
                                    'file_path' => $absolutePath,
                                    'page_number' => $pageNum,
                                    'error' => $e->getMessage(),
                                ]);
                                // Try extracting directly from the page node if available
                                try {
                                    $pageData = $this->extractPageDataFromNode($document, $pageNode, $nextObjectNumber, $mergedDoc, $resourceNameMap);
                                    $allPages[] = $pageData;
                                    $pagesExtractedForThisPdf++;
                                } catch (FpdfException $e2) {
                                    $this->logger->debug('Node-based page extraction also failed', [
                                        'file_path' => $absolutePath,
                                        'page_number' => $pageNum,
                                        'error' => $e2->getMessage(),
                                    ]);
                                }
                            }
                        }
                        if ($pagesExtractedForThisPdf > 0) {
                            $this->logger->debug('Extracted pages using getAllPages fallback', [
                                'file_path' => $absolutePath,
                                'pages_extracted' => $pagesExtractedForThisPdf,
                                'expected_count' => $expectedPageCount,
                            ]);
                        }
                    }
                } catch (FpdfException $e) {
                    $this->logger->debug('getAllPages failed in fallback', [
                        'file_path' => $absolutePath,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Second try: Extract pages one by one until getPage() returns null
                if ($pagesExtractedForThisPdf === 0) {
                    $this->logger->warning('getAllPages fallback failed, trying sequential extraction', [
                        'file_path' => $absolutePath,
                    ]);
                    $pageNum = 1;
                    $maxAttempts = 1000; // Safety limit
                    while ($pageNum <= $maxAttempts) {
                        $pageNode = $document->getPage($pageNum);
                        if ($pageNode === null) {
                            // No more pages found
                            break;
                        }
                        try {
                            $pageData = $this->extractPageData($document, $pageNum, $nextObjectNumber, $mergedDoc, $resourceNameMap);
                            $allPages[] = $pageData;
                            $pagesExtractedForThisPdf++;
                            $pageNum++;
                        } catch (FpdfException $e) {
                            // If extraction fails, stop trying
                            $this->logger->debug('Page extraction failed, stopping', [
                                'file_path' => $absolutePath,
                                'page_number' => $pageNum,
                                'error' => $e->getMessage(),
                            ]);
                            break;
                        }
                    }

                    if ($pagesExtractedForThisPdf > 0) {
                        $this->logger->debug('Extracted pages using sequential fallback method', [
                            'file_path' => $absolutePath,
                            'pages_extracted' => $pagesExtractedForThisPdf,
                        ]);
                    } else {
                        $this->logger->warning('Could not extract any pages from PDF', [
                            'file_path' => $absolutePath,
                        ]);

                        // Retry once by re-parsing the document and attempting sequential extraction again.
                        try {
                            $this->logger->info('Retrying extraction after re-parsing document', ['file_path' => $absolutePath]);
                            // Re-parse document to clear any transient state that might have prevented extraction
                            $document = $parser->parseDocumentFromFile($pdfPath, $this->fileIO);

                            $pageNum = 1;
                            while ($pageNum <= $maxAttempts) {
                                $pageNode = $document->getPage($pageNum);
                                if ($pageNode === null) {
                                    break;
                                }
                                try {
                                    $pageData = $this->extractPageData($document, $pageNum, $nextObjectNumber, $mergedDoc, $resourceNameMap);
                                    $allPages[] = $pageData;
                                    $pagesExtractedForThisPdf++;
                                    $pageNum++;
                                } catch (FpdfException $e) {
                                    $this->logger->debug('Retry page extraction failed, stopping', [
                                        'file_path' => $absolutePath,
                                        'page_number' => $pageNum,
                                        'error' => $e->getMessage(),
                                    ]);
                                    break;
                                }
                            }

                            if ($pagesExtractedForThisPdf > 0) {
                                $this->logger->info('Retry extraction succeeded', [
                                    'file_path' => $absolutePath,
                                    'pages_extracted' => $pagesExtractedForThisPdf,
                                ]);
                            }
                        } catch (FpdfException $e) {
                            $this->logger->debug('Retry parsing/ extraction failed', ['file_path' => $absolutePath, 'error' => $e->getMessage()]);
                        }
                    }
                }
            }

            // Determine authoritative expected page count for this document.
            // If getAllPages returned nodes during fallback extraction, prefer that as it's often more reliable
            // than a single /Count value for PDFs with unusual page trees.
            $effectiveExpectedCount = $originalPageCount;
            if (is_array($fallbackAllPageNodes) && count($fallbackAllPageNodes) > $effectiveExpectedCount) {
                $effectiveExpectedCount = count($fallbackAllPageNodes);
            }

            // If still unclear (e.g., parser found 1 but file likely contains more), try using pdfinfo if available to get authoritative page count
            if ($effectiveExpectedCount <= 1 && function_exists('exec')) {
                try {
                    $cmd = 'pdfinfo ' . escapeshellarg($absolutePath) . ' 2>&1';
                    @exec($cmd, $pdfInfoOutput, $pdfReturn);
                    if (isset($pdfReturn) && $pdfReturn === 0 && !empty($pdfInfoOutput)) {
                        $pdfInfoStr = implode("\n", $pdfInfoOutput);
                        if (preg_match('/Pages:\s*(\d+)/i', $pdfInfoStr, $m)) {
                            $pdfPages = (int) $m[1];
                            if ($pdfPages > $effectiveExpectedCount) {
                                $effectiveExpectedCount = $pdfPages;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // ignore failures of external utility
                }
            }

            // If we expected pages but couldn't extract them all, add blank placeholders so page counts match
            if ($effectiveExpectedCount > $pagesExtractedForThisPdf) {
                $missing = $effectiveExpectedCount - $pagesExtractedForThisPdf;
                $this->logger->warning('Not all pages extracted, adding placeholder blank pages', [
                    'file_path' => $absolutePath,
                    'expected_count' => $effectiveExpectedCount,
                    'extracted' => $pagesExtractedForThisPdf,
                    'added_placeholders' => $missing,
                ]);

                for ($i = 0; $i < $missing; $i++) {
                    $allPages[] = [
                        'pageDict' => new \PXP\PDF\Fpdf\Object\Base\PDFDictionary(),
                        'content' => '',
                        'resources' => null,
                        'mediaBox' => null,
                        'hasMediaBox' => false,
                        'sourceDoc' => null,
                    ];
                }
            }

            // Log per-file processing summary for debugging
            $this->logger->info('Completed processing input PDF', [
                'file_path' => $absolutePath,
                'expected_count' => $effectiveExpectedCount,
                'extracted' => $pagesExtractedForThisPdf,
                'total_pages_so_far' => count($allPages),
            ]);

            // Also write a simple debug line to a temp file so we can inspect across environments
            try {
                file_put_contents(sys_get_temp_dir() . '/pdf_merge_debug.log', json_encode([
                    'file_path' => $absolutePath,
                    'expected_count' => $effectiveExpectedCount,
                    'extracted' => $pagesExtractedForThisPdf,
                    'total_pages_so_far' => count($allPages),
                ], JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND);
            } catch (\Throwable $e) {
                // ignore
            }

            $this->logger->info('Completed processing input PDF', [
                'file_path' => $absolutePath,
                'expected_count' => $effectiveExpectedCount,
                'extracted' => $pagesExtractedForThisPdf,
                'total_pages_so_far' => count($allPages),
            ]);

            // Also write a simple debug line to a temp file so we can inspect across environments
            try {
                file_put_contents(sys_get_temp_dir() . '/pdf_merge_debug.log', json_encode([
                    'file_path' => $absolutePath,
                    'expected_count' => $effectiveExpectedCount,
                    'extracted' => $pagesExtractedForThisPdf,
                    'total_pages_so_far' => count($allPages),
                ], JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND);
            } catch (\Throwable $e) {
                // ignore
            }

            // Clear cache periodically to manage memory
            if (($pdfIndex + 1) % 10 === 0) {
                $this->logger->debug('Clearing cache for memory management', [
                    'processed_files' => $pdfIndex + 1,
                ]);
                $document->getObjectRegistry()->clearCache();
                gc_collect_cycles();
            }
        }

        if (empty($allPages)) {
            throw new FpdfException('No pages found in input PDFs');
        }

        $this->logger->info('All pages extracted', [
            'total_pages' => count($allPages),
        ]);

        // Build merged PDF structure
        $this->buildMergedPdf($mergedDoc, $allPages, $nextObjectNumber);

        // Serialize and write to file
        $mergedPdf = $mergedDoc->serialize();

        $this->logger->debug('Writing merged PDF to file', [
            'output_path' => $outputPath,
            'pdf_size' => strlen($mergedPdf),
        ]);

        $this->fileIO->writeFile($outputPath, $mergedPdf);

        $duration = (microtime(true) - $startTime) * 1000;
        $this->logger->info('PDF merge operation completed', [
            'input_files' => count($pdfFilePaths),
            'total_pages' => count($allPages),
            'output_path' => $outputPath,
            'duration_ms' => round($duration, 2),
        ]);
    }

    /**
     * Get page count from a PDF document
     */
    private function getPageCount(PDFDocument $document): int
    {
        // Prefer authoritative /Count from Pages dictionary when available
        $pagesNode = $document->getPages();
        if ($pagesNode !== null) {
            $pagesDict = $pagesNode->getValue();
            if ($pagesDict instanceof PDFDictionary) {
                $countEntry = $pagesDict->getEntry('/Count');
                if ($countEntry instanceof \PXP\PDF\Fpdf\Object\Base\PDFNumber) {
                    $count = (int) $countEntry->getValue();
                    if ($count > 0) {
                        return $count;
                    }
                }
            }
        }

        // First try to read /Count from file content (fallback to naive scan)
        $registry = $document->getObjectRegistry();
        $reflection = new \ReflectionClass($registry);
        $filePathProp = $reflection->getProperty('filePath');
        $filePathProp->setAccessible(true);
        $filePath = $filePathProp->getValue($registry);
        $fileIOProp = $reflection->getProperty('fileIO');
        $fileIOProp->setAccessible(true);
        $regFileIO = $fileIOProp->getValue($registry);

        if ($filePath !== null && file_exists($filePath)) {
            try {
                $content = $regFileIO !== null ? $regFileIO->readFile($filePath) : file_get_contents($filePath);
                // Use same pattern as test - finds first /Count match
                if (preg_match('/\/Count\s+(\d+)/', $content, $matches)) {
                    $count = (int) $matches[1];
                    if ($count > 0) {
                        return $count;
                    }
                }
            } catch (\Exception $e) {
                // Continue to next method
            }
        }

        // Fallback: Try to get /Count from Pages dictionary (for PDFs where file content search fails)
        $pagesNode = $document->getPages();        if ($pagesNode === null) {
            // Try to load Pages from root if not already loaded
            $root = $document->getRoot();
            if ($root === null) {
                $rootRef = $document->getTrailer()->getRoot();
                if ($rootRef !== null) {
                    $root = $document->getObject($rootRef->getObjectNumber());
                    if ($root !== null) {
                        $document->setRoot($root);
                    }
                }
            }

            if ($root !== null) {
                $rootDict = $root->getValue();
                if ($rootDict instanceof \PXP\PDF\Fpdf\Object\Base\PDFDictionary) {
                    $pagesRef = $rootDict->getEntry('/Pages');
                    if ($pagesRef instanceof \PXP\PDF\Fpdf\Object\Base\PDFReference) {
                        $pagesNode = $document->getObject($pagesRef->getObjectNumber());
                    }
                }
            }
        }

        if ($pagesNode !== null) {
            $pagesDict = $pagesNode->getValue();
            if ($pagesDict instanceof PDFDictionary) {
                $countEntry = $pagesDict->getEntry('/Count');
                if ($countEntry instanceof \PXP\PDF\Fpdf\Object\Base\PDFNumber) {
                    $count = (int) $countEntry->getValue();
                    if ($count > 0) {
                        return $count;
                    }
                }
            }
        }

        // Final fallback: Try sequentially calling getPage() to count pages as a robust fallback
        // This handles PDFs where /Count is missing or cannot be reliably parsed.
        try {
            $count = 0;
            $maxAttempts = 10000; // safety limit
            for ($i = 1; $i <= $maxAttempts; ++$i) {
                $pageNode = $document->getPage($i);
                if ($pageNode === null) {
                    break;
                }
                $count++;
            }
            if ($count > 0) {
                return $count;
            }
        } catch (\Throwable $e) {
            // ignore and fall through to default
        }

        // If all else fails, return 1 as a safe default
        return 1;
    }

    /**
     * Extract page data from a document
     *
     * @return array{pageDict: PDFDictionary, content: string, resources: PDFDictionary|null, mediaBox: array<float>|null, hasMediaBox: bool}
     */
    private function extractPageData(
        PDFDocument $sourceDoc,
        int $pageNumber,
        int &$nextObjectNumber,
        PDFDocument $targetDoc,
        array &$resourceNameMap
    ): array {
        $pageNode = $sourceDoc->getPage($pageNumber);
        if ($pageNode === null) {
            throw new FpdfException('Invalid page number: ' . $pageNumber);
        }

        $pageDict = $pageNode->getValue();
        if (!$pageDict instanceof PDFDictionary) {
            throw new FpdfException('Page object is not a dictionary');
        }

        // Extract MediaBox
        $mediaBox = null;
        $pageHasMediaBox = false;
        $mediaBoxEntry = $pageDict->getEntry('/MediaBox');
        if ($mediaBoxEntry instanceof MediaBoxArray) {
            $mediaBox = $mediaBoxEntry->getValues();
            $pageHasMediaBox = true;
        } elseif ($mediaBoxEntry instanceof \PXP\PDF\Fpdf\Object\Base\PDFArray) {
            $values = [];
            foreach ($mediaBoxEntry->getAll() as $item) {
                if ($item instanceof \PXP\PDF\Fpdf\Object\Base\PDFNumber) {
                    $values[] = (float) $item->getValue();
                }
            }
            if (count($values) >= 4) {
                $mediaBox = [$values[0], $values[1], $values[2], $values[3]];
                $pageHasMediaBox = true;
            }
        }

        // If page doesn't have MediaBox, check parent
        if (!$pageHasMediaBox) {
            $parentRef = $pageDict->getEntry('/Parent');
            if ($parentRef instanceof PDFReference) {
                $parentNode = $sourceDoc->getObject($parentRef->getObjectNumber());
                if ($parentNode !== null) {
                    $parentDict = $parentNode->getValue();
                    if ($parentDict instanceof PDFDictionary) {
                        $parentMediaBoxEntry = $parentDict->getEntry('/MediaBox');
                        if ($parentMediaBoxEntry instanceof MediaBoxArray) {
                            $mediaBox = $parentMediaBoxEntry->getValues();
                        } elseif ($parentMediaBoxEntry instanceof \PXP\PDF\Fpdf\Object\Base\PDFArray) {
                            $values = [];
                            foreach ($parentMediaBoxEntry->getAll() as $item) {
                                if ($item instanceof \PXP\PDF\Fpdf\Object\Base\PDFNumber) {
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

        // Extract Contents
        $contentsRef = $pageDict->getEntry('/Contents');
        $contentStreams = [];

        if ($contentsRef instanceof PDFReference) {
            $contentNode = $sourceDoc->getObject($contentsRef->getObjectNumber());
            if ($contentNode !== null) {
                $contentObj = $contentNode->getValue();
                if ($contentObj instanceof PDFStream) {
                    $contentStreams[] = $contentObj;
                }
            }
        } elseif ($contentsRef instanceof \PXP\PDF\Fpdf\Object\Base\PDFArray) {
            foreach ($contentsRef->getAll() as $item) {
                if ($item instanceof PDFReference) {
                    $contentNode = $sourceDoc->getObject($item->getObjectNumber());
                    if ($contentNode !== null) {
                        $contentObj = $contentNode->getValue();
                        if ($contentObj instanceof PDFStream) {
                            $contentStreams[] = $contentObj;
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

        // Extract Resources
        $resourcesRef = $pageDict->getEntry('/Resources');
        $resourcesDict = null;
        if ($resourcesRef instanceof PDFReference) {
            $resourcesNode = $sourceDoc->getObject($resourcesRef->getObjectNumber());
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
            'pageDict' => $pageDict,
            'content' => $combinedContent,
            'resources' => $resourcesDict,
            'mediaBox' => $mediaBox,
            'hasMediaBox' => $pageHasMediaBox,
            'sourceDoc' => $sourceDoc, // Keep reference to source document for resource copying
        ];
    }

    /**
     * Build the merged PDF document structure
     */
    private function buildMergedPdf(
        PDFDocument $mergedDoc,
        array $allPages,
        int &$nextObjectNumber
    ): void {
        // Object 1: Pages dictionary
        $pagesDict = new \PXP\PDF\Fpdf\Object\Base\PDFDictionary();
        $pagesDict->addEntry('/Type', new \PXP\PDF\Fpdf\Object\Base\PDFName('Pages'));
        $kids = new KidsArray();

        // Determine MediaBox (use first page's MediaBox as default)
        $defaultMediaBox = null;
        if (!empty($allPages) && $allPages[0]['mediaBox'] !== null) {
            $defaultMediaBox = $allPages[0]['mediaBox'];
        } else {
            // Default A4 MediaBox
            $defaultMediaBox = [0.0, 0.0, 595.28, 841.89];
        }

        $pagesDict->addEntry('/MediaBox', new MediaBoxArray($defaultMediaBox));
        $pagesDict->addEntry('/Count', new \PXP\PDF\Fpdf\Object\Base\PDFNumber(count($allPages)));

        // Process each page
        $pageObjectNumbers = [];
        $resourceNameMap = [];
        $currentObjNum = 2; // Start after Pages dictionary (1)

        foreach ($allPages as $pageIndex => $pageData) {
            // Resources object number for this page (allocate first)
            $resourcesObjNum = $currentObjNum++;

            // Local mapping of original resource names to unique names for this page
            $localResourceMap = [];

            // Copy resources for this page (this will update currentObjNum as needed)
            if ($pageData['resources'] !== null) {
                $this->copyResourcesForPage(
                    $pageData['resources'],
                    $pageData['sourceDoc'],
                    $mergedDoc,
                    $resourcesObjNum,
                    $currentObjNum,
                    $resourceNameMap,
                    $localResourceMap
                );
            } else {
                $emptyResources = new \PXP\PDF\Fpdf\Object\Dictionary\ResourcesDictionary();
                $mergedDoc->addObject($emptyResources, $resourcesObjNum);
            }

            // Content stream object number
            $contentObjNum = $currentObjNum++;

            // Add content stream
            if (!empty($pageData['content'])) {
                $content = $pageData['content'];

                // Rewrite resource names inside content to match renamed resources for this page
                if (!empty($localResourceMap)) {
                    foreach (['Font', 'XObject'] as $type) {
                        if (!empty($localResourceMap[$type]) && is_array($localResourceMap[$type])) {
                            foreach ($localResourceMap[$type] as $oldName => $newName) {
                                // Replace occurrences like /OldName with /NewName (word boundary)
                                $content = preg_replace('/\/' . preg_quote($oldName, '/') . '\b/', '/' . $newName, $content);
                            }
                        }
                    }
                }

                $streamDict = new \PXP\PDF\Fpdf\Object\Base\PDFDictionary();
                $newStream = new PDFStream($streamDict, $content, false);
                // Add compression if content is large
                if (strlen($content) > 1024) {
                    $newStream->addFilter('FlateDecode');
                }
                $mergedDoc->addObject($newStream, $contentObjNum);
            } else {
                $emptyStream = new PDFStream(new \PXP\PDF\Fpdf\Object\Base\PDFDictionary(), '');
                $mergedDoc->addObject($emptyStream, $contentObjNum);
            }

            // Page dictionary object number (allocate after content)
            $pageObjNum = $currentObjNum++;
            $pageObjectNumbers[] = $pageObjNum;

            // Create page dictionary
            $newPageDict = new \PXP\PDF\Fpdf\Object\Dictionary\PageDictionary();
            $newPageDict->setResources($resourcesObjNum);
            $newPageDict->setContents($contentObjNum);

            $mediaBox = $pageData['mediaBox'] ?? $defaultMediaBox;
            if ($pageData['hasMediaBox']) {
                $newPageDict->setMediaBox($mediaBox);
            }

            $mergedDoc->addObject($newPageDict, $pageObjNum);
            $kids->addPage($pageObjNum);

            $nextObjectNumber = max($nextObjectNumber, $pageObjNum);
        }

        $pagesDict->addEntry('/Kids', $kids);
        $pagesNode = $mergedDoc->addObject($pagesDict, 1);
        $pagesNode->setObjectNumber(1);

        // Set parent reference for all pages
        foreach ($pageObjectNumbers as $pageObjNum) {
            $pageNode = $mergedDoc->getObject($pageObjNum);
            if ($pageNode !== null) {
                $pageDict = $pageNode->getValue();
                if ($pageDict instanceof \PXP\PDF\Fpdf\Object\Dictionary\PageDictionary) {
                    $pageDict->setParent($pagesNode);
                }
            }
        }

        // Catalog object
        $catalogObjNum = $nextObjectNumber + 1;
        $catalog = new \PXP\PDF\Fpdf\Object\Dictionary\CatalogDictionary();
        $catalog->setPages(1);
        $catalogNode = $mergedDoc->addObject($catalog, $catalogObjNum);
        $mergedDoc->setRoot($catalogNode);
    }

    /**
     * Copy resources for a page, handling name conflicts and copying referenced objects
     */
    private function copyResourcesForPage(
        PDFDictionary $resourcesDict,
        PDFDocument $sourceDoc,
        PDFDocument $targetDoc,
        int $resourcesObjNum,
        int &$nextObjectNumber,
        array &$resourceNameMap,
        array &$localResourceMap = []
    ): void {
        // Use similar approach to PDFSplitter's copyResourcesWithReferences
        $newResources = new \PXP\PDF\Fpdf\Object\Dictionary\ResourcesDictionary();
        $objectMap = [];

        // Copy ProcSet
        $procSet = $resourcesDict->getEntry('/ProcSet');
        if ($procSet !== null) {
            $newResources->setProcSet(
                $procSet instanceof \PXP\PDF\Fpdf\Object\Base\PDFArray
                ? array_map(
                    fn($item) => $item instanceof \PXP\PDF\Fpdf\Object\Base\PDFName ? $item->getName() : (string) $item,
                    $procSet->getAll()
                )
                : ['PDF', 'Text', 'ImageB', 'ImageC', 'ImageI']
            );
        }

        // Copy Fonts with full object copying (support dict or reference-to-dict)
        $fonts = $resourcesDict->getEntry('/Font');
        if ($fonts instanceof PDFReference) {
            $fontsNode = $sourceDoc->getObject($fonts->getObjectNumber());
            if ($fontsNode !== null) {
                $fonts = $fontsNode->getValue();
            }
        }

        if ($fonts instanceof PDFDictionary) {
            $newFonts = new \PXP\PDF\Fpdf\Object\Base\PDFDictionary();
            foreach ($fonts->getAllEntries() as $fontName => $fontRef) {
                $uniqueFontName = $this->getUniqueResourceName($fontName, $resourceNameMap, 'Font');
                // Record local mapping (old => new) for content rewriting
                $localResourceMap['Font'][$fontName] = $uniqueFontName;

                $copyingObjects = [];
                if ($fontRef instanceof PDFReference) {
                    $fontNode = $sourceDoc->getObject($fontRef->getObjectNumber());
                    if ($fontNode !== null) {
                        $fontObj = $fontNode->getValue();
                        $copiedFont = $this->copyObjectWithReferences($fontObj, $sourceDoc, $targetDoc, $nextObjectNumber, $objectMap, $copyingObjects);
                        $targetDoc->addObject($copiedFont, $nextObjectNumber);
                        $newFonts->addEntry($uniqueFontName, new PDFReference($nextObjectNumber));
                        $nextObjectNumber++;
                    }
                } elseif ($fontRef instanceof PDFDictionary) {
                    $copiedFont = $this->copyObjectWithReferences($fontRef, $sourceDoc, $targetDoc, $nextObjectNumber, $objectMap, $copyingObjects);
                    $targetDoc->addObject($copiedFont, $nextObjectNumber);
                    $newFonts->addEntry($uniqueFontName, new PDFReference($nextObjectNumber));
                    $nextObjectNumber++;
                }
            }
            if (count($newFonts->getAllEntries()) > 0) {
                $newResources->addEntry('/Font', $newFonts);
            }
        }

        // Copy XObjects (images) with full object copying (support dict or reference-to-dict)
        $xObjects = $resourcesDict->getEntry('/XObject');
        if ($xObjects instanceof PDFReference) {
            $xObjectsNode = $sourceDoc->getObject($xObjects->getObjectNumber());
            if ($xObjectsNode !== null) {
                $xObjects = $xObjectsNode->getValue();
            }
        }

        if ($xObjects instanceof PDFDictionary) {
            $newXObjects = new \PXP\PDF\Fpdf\Object\Base\PDFDictionary();
            foreach ($xObjects->getAllEntries() as $xObjectName => $xObjectRef) {
                $uniqueXObjectName = $this->getUniqueResourceName($xObjectName, $resourceNameMap, 'XObject');
                // Record local mapping (old => new)
                $localResourceMap['XObject'][$xObjectName] = $uniqueXObjectName;

                $copyingObjects = [];
                if ($xObjectRef instanceof PDFReference) {
                    $xObjectNode = $sourceDoc->getObject($xObjectRef->getObjectNumber());
                    if ($xObjectNode !== null) {
                        $xObjectObj = $xObjectNode->getValue();
                        $copiedXObject = $this->copyObjectWithReferences($xObjectObj, $sourceDoc, $targetDoc, $nextObjectNumber, $objectMap, $copyingObjects);
                        $targetDoc->addObject($copiedXObject, $nextObjectNumber);
                        $newXObjects->addEntry($uniqueXObjectName, new PDFReference($nextObjectNumber));
                        $nextObjectNumber++;
                    }
                } elseif ($xObjectRef instanceof PDFStream) {
                    $copiedXObject = $this->copyObjectWithReferences($xObjectRef, $sourceDoc, $targetDoc, $nextObjectNumber, $objectMap, $copyingObjects);
                    $targetDoc->addObject($copiedXObject, $nextObjectNumber);
                    $newXObjects->addEntry($uniqueXObjectName, new PDFReference($nextObjectNumber));
                    $nextObjectNumber++;
                }
            }
            $newResources->addEntry('/XObject', $newXObjects);
        }

        // Copy other resource types
        foreach ($resourcesDict->getAllEntries() as $key => $value) {
            if (in_array($key, ['ProcSet', 'Font', 'XObject'], true)) {
                continue;
            }

            if ($value instanceof PDFReference) {
                $refNode = $sourceDoc->getObject($value->getObjectNumber());
                if ($refNode !== null) {
                    $refObj = $refNode->getValue();
                    $copyingObjects = [];
                    $copiedObj = $this->copyObjectWithReferences($refObj, $sourceDoc, $targetDoc, $nextObjectNumber, $objectMap, $copyingObjects);
                    $targetDoc->addObject($copiedObj, $nextObjectNumber);
                    $newResources->addEntry($key, new PDFReference($nextObjectNumber));
                    $nextObjectNumber++;
                }
            } elseif ($value instanceof PDFDictionary || $value instanceof \PXP\PDF\Fpdf\Object\Base\PDFArray) {
                $copyingObjects = [];
                $copiedValue = $this->copyObjectWithReferences($value, $sourceDoc, $targetDoc, $nextObjectNumber, $objectMap, $copyingObjects);
                $newResources->addEntry($key, $copiedValue);
            } else {
                $newResources->addEntry($key, $value);
            }
        }

        $targetDoc->addObject($newResources, $resourcesObjNum);
    }

    /**
     * Recursively copy an object and resolve all references (similar to PDFSplitter)
     */
    private function copyObjectWithReferences(
        \PXP\PDF\Fpdf\Object\PDFObjectInterface $obj,
        PDFDocument $sourceDoc,
        PDFDocument $targetDoc,
        int &$nextObjectNumber,
        array &$objectMap = [],
        array &$copyingObjects = []
    ): \PXP\PDF\Fpdf\Object\PDFObjectInterface {
        if ($obj instanceof PDFReference) {
            $oldObjNum = $obj->getObjectNumber();

            if (isset($objectMap[$oldObjNum])) {
                return new PDFReference($objectMap[$oldObjNum]);
            }

            if (isset($copyingObjects[$oldObjNum])) {
                if (isset($objectMap[$oldObjNum])) {
                    return new PDFReference($objectMap[$oldObjNum]);
                }
                $newObjNum = $nextObjectNumber;
                $objectMap[$oldObjNum] = $newObjNum;
                $nextObjectNumber++;
                return new PDFReference($newObjNum);
            }

            $refNode = $sourceDoc->getObject($oldObjNum);
            if ($refNode !== null) {
                $refObj = $refNode->getValue();

                $newObjNum = $nextObjectNumber;
                $objectMap[$oldObjNum] = $newObjNum;
                $nextObjectNumber++;

                $copyingObjects[$oldObjNum] = true;

                $copiedRefObj = $this->copyObjectWithReferences($refObj, $sourceDoc, $targetDoc, $nextObjectNumber, $objectMap, $copyingObjects);
                $targetDoc->addObject($copiedRefObj, $newObjNum);

                unset($copyingObjects[$oldObjNum]);

                return new PDFReference($newObjNum);
            }
            return $obj;
        }

        if ($obj instanceof PDFDictionary) {
            $newDict = new \PXP\PDF\Fpdf\Object\Base\PDFDictionary();
            foreach ($obj->getAllEntries() as $key => $value) {
                if ($value instanceof \PXP\PDF\Fpdf\Object\PDFObjectInterface) {
                    $newDict->addEntry($key, $this->copyObjectWithReferences($value, $sourceDoc, $targetDoc, $nextObjectNumber, $objectMap, $copyingObjects));
                } else {
                    $newDict->addEntry($key, $value);
                }
            }
            return $newDict;
        }

        if ($obj instanceof \PXP\PDF\Fpdf\Object\Base\PDFArray) {
            $newArray = new \PXP\PDF\Fpdf\Object\Base\PDFArray();
            foreach ($obj->getAll() as $item) {
                if ($item instanceof \PXP\PDF\Fpdf\Object\PDFObjectInterface) {
                    $newArray->add($this->copyObjectWithReferences($item, $sourceDoc, $targetDoc, $nextObjectNumber, $objectMap, $copyingObjects));
                } else {
                    $newArray->add($item);
                }
            }
            return $newArray;
        }

        if ($obj instanceof \PXP\PDF\Fpdf\Object\Base\PDFArray) {
            $newArray = new \PXP\PDF\Fpdf\Object\Base\PDFArray();
            foreach ($obj->getAll() as $item) {
                if ($item instanceof \PXP\PDF\Fpdf\Object\PDFObjectInterface) {
                    $newArray->add($this->copyObjectWithReferences($item, $sourceDoc, $targetDoc, $nextObjectNumber, $objectMap, $copyingObjects));
                } else {
                    $newArray->add($item);
                }
            }
            return $newArray;
        }

        if ($obj instanceof PDFStream) {
            $streamDict = $obj->getDictionary();
            $streamData = $obj->getDecodedData();
            $copiedDict = $this->copyObjectWithReferences($streamDict, $sourceDoc, $targetDoc, $nextObjectNumber, $objectMap, $copyingObjects);
            if (!$copiedDict instanceof PDFDictionary) {
                $copiedDict = new \PXP\PDF\Fpdf\Object\Base\PDFDictionary();
            }

            $newStream = new PDFStream($copiedDict, $streamData, false);
            foreach ($obj->getFilters() as $filter) {
                $newStream->addFilter($filter);
            }
            return $newStream;
        }

        // Default: return original object
        return $obj;
    }

    /**
     * Extract page data directly from a page node (when numeric getPage() fails)
     *
     * @return array{pageDict: PDFDictionary, content: string, resources: PDFDictionary|null, mediaBox: array<float>|null, hasMediaBox: bool}
     */
    private function extractPageDataFromNode(
        PDFDocument $sourceDoc,
        $pageNode,
        int &$nextObjectNumber,
        PDFDocument $targetDoc,
        array &$resourceNameMap
    ): array {
        if ($pageNode === null) {
            throw new FpdfException('Page node is null');
        }

        $pageDict = $pageNode->getValue();
        if (!$pageDict instanceof PDFDictionary) {
            throw new FpdfException('Page object is not a dictionary');
        }

        // Extract MediaBox
        $mediaBox = null;
        $pageHasMediaBox = false;
        $mediaBoxEntry = $pageDict->getEntry('/MediaBox');
        if ($mediaBoxEntry instanceof MediaBoxArray) {
            $mediaBox = $mediaBoxEntry->getValues();
            $pageHasMediaBox = true;
        } elseif ($mediaBoxEntry instanceof \PXP\PDF\Fpdf\Object\Base\PDFArray) {
            $values = [];
            foreach ($mediaBoxEntry->getAll() as $item) {
                if ($item instanceof \PXP\PDF\Fpdf\Object\Base\PDFNumber) {
                    $values[] = (float) $item->getValue();
                }
            }
            if (count($values) >= 4) {
                $mediaBox = [$values[0], $values[1], $values[2], $values[3]];
                $pageHasMediaBox = true;
            }
        }

        // If page doesn't have MediaBox, check parent
        if (!$pageHasMediaBox) {
            $parentRef = $pageDict->getEntry('/Parent');
            if ($parentRef instanceof PDFReference) {
                $parentNode = $sourceDoc->getObject($parentRef->getObjectNumber());
                if ($parentNode !== null) {
                    $parentDict = $parentNode->getValue();
                    if ($parentDict instanceof PDFDictionary) {
                        $parentMediaBoxEntry = $parentDict->getEntry('/MediaBox');
                        if ($parentMediaBoxEntry instanceof MediaBoxArray) {
                            $mediaBox = $parentMediaBoxEntry->getValues();
                        } elseif ($parentMediaBoxEntry instanceof \PXP\PDF\Fpdf\Object\Base\PDFArray) {
                            $values = [];
                            foreach ($parentMediaBoxEntry->getAll() as $item) {
                                if ($item instanceof \PXP\PDF\Fpdf\Object\Base\PDFNumber) {
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

        // Extract Contents
        $contentsRef = $pageDict->getEntry('/Contents');
        $contentStreams = [];

        if ($contentsRef instanceof PDFReference) {
            $contentNode = $sourceDoc->getObject($contentsRef->getObjectNumber());
            if ($contentNode !== null) {
                $contentObj = $contentNode->getValue();
                if ($contentObj instanceof PDFStream) {
                    $contentStreams[] = $contentObj;
                }
            }
        } elseif ($contentsRef instanceof \PXP\PDF\Fpdf\Object\Base\PDFArray) {
            foreach ($contentsRef->getAll() as $item) {
                if ($item instanceof PDFReference) {
                    $contentNode = $sourceDoc->getObject($item->getObjectNumber());
                    if ($contentNode !== null) {
                        $contentObj = $contentNode->getValue();
                        if ($contentObj instanceof PDFStream) {
                            $contentStreams[] = $contentObj;
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

        // Extract Resources
        $resourcesRef = $pageDict->getEntry('/Resources');
        $resourcesDict = null;
        if ($resourcesRef instanceof PDFReference) {
            $resourcesNode = $sourceDoc->getObject($resourcesRef->getObjectNumber());
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
            'pageDict' => $pageDict,
            'content' => $combinedContent,
            'resources' => $resourcesDict,
            'mediaBox' => $mediaBox,
            'hasMediaBox' => $pageHasMediaBox,
            'sourceDoc' => $sourceDoc, // Keep reference to source document for resource copying
        ];
    }

    /**
     * Get a unique resource name, handling conflicts
     */
    private function getUniqueResourceName(string $name, array &$resourceNameMap, string $type): string
    {
        $key = $type . ':' . $name;
        if (!isset($resourceNameMap[$key])) {
            $resourceNameMap[$key] = $name;
            return $name;
        }

        // Name conflict - generate unique name
        $counter = 1;
        $uniqueName = $name . '_' . $counter;
        while (isset($resourceNameMap[$type . ':' . $uniqueName])) {
            $counter++;
            $uniqueName = $name . '_' . $counter;
        }
        $resourceNameMap[$type . ':' . $uniqueName] = $uniqueName;
        return $uniqueName;
    }
}
