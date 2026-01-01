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

            $this->logger->debug('Extracting pages from PDF', [
                'file_path' => $absolutePath,
                'page_count' => $pageCount,
            ]);

            // Extract all pages from this document
            for ($pageNum = 1; $pageNum <= $pageCount; $pageNum++) {
                $pageData = $this->extractPageData($document, $pageNum, $nextObjectNumber, $mergedDoc, $resourceNameMap);
                $allPages[] = $pageData;
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
        $root = $document->getRoot();
        if ($root === null) {
            return 0;
        }

        $catalog = $root->getValue();
        if (!$catalog instanceof \PXP\PDF\Fpdf\Object\Dictionary\CatalogDictionary) {
            return 0;
        }

        $pagesRef = $catalog->getPages();
        if ($pagesRef === null) {
            return 0;
        }

        $pagesNode = $document->getObject($pagesRef->getObjectNumber());
        if ($pagesNode === null) {
            return 0;
        }

        $pagesDict = $pagesNode->getValue();
        if (!$pagesDict instanceof PDFDictionary) {
            return 0;
        }

        $countEntry = $pagesDict->getEntry('/Count');
        if ($countEntry instanceof \PXP\PDF\Fpdf\Object\Base\PDFNumber) {
            return (int) $countEntry->getValue();
        }

        return 0;
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

            // Copy resources for this page (this will update currentObjNum as needed)
            if ($pageData['resources'] !== null) {
                $this->copyResourcesForPage(
                    $pageData['resources'],
                    $pageData['sourceDoc'],
                    $mergedDoc,
                    $resourcesObjNum,
                    $currentObjNum,
                    $resourceNameMap
                );
            } else {
                $emptyResources = new \PXP\PDF\Fpdf\Object\Dictionary\ResourcesDictionary();
                $mergedDoc->addObject($emptyResources, $resourcesObjNum);
            }

            // Content stream object number
            $contentObjNum = $currentObjNum++;

            // Add content stream
            if (!empty($pageData['content'])) {
                $streamDict = new \PXP\PDF\Fpdf\Object\Base\PDFDictionary();
                $newStream = new PDFStream($streamDict, $pageData['content'], false);
                // Add compression if content is large
                if (strlen($pageData['content']) > 1024) {
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
        array &$resourceNameMap
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

        // Copy Fonts with full object copying
        $fonts = $resourcesDict->getEntry('/Font');
        if ($fonts instanceof PDFDictionary) {
            $newFonts = new \PXP\PDF\Fpdf\Object\Base\PDFDictionary();
            foreach ($fonts->getAllEntries() as $fontName => $fontRef) {
                $uniqueFontName = $this->getUniqueResourceName($fontName, $resourceNameMap, 'Font');
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

        // Copy XObjects (images) with full object copying
        $xObjects = $resourcesDict->getEntry('/XObject');
        if ($xObjects instanceof PDFDictionary) {
            $newXObjects = new \PXP\PDF\Fpdf\Object\Base\PDFDictionary();
            foreach ($xObjects->getAllEntries() as $xObjectName => $xObjectRef) {
                $uniqueXObjectName = $this->getUniqueResourceName($xObjectName, $resourceNameMap, 'XObject');
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

        return $obj;
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
