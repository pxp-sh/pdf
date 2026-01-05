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
namespace PXP\PDF\Fpdf\Merger;

use function count;
use function ltrim;
use function preg_quote;
use function preg_replace;
use function spl_object_id;
use Psr\Log\LoggerInterface;
use PXP\PDF\Fpdf\Exception\FpdfException;
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
use PXP\PDF\Fpdf\Object\PDFObjectInterface;
use PXP\PDF\Fpdf\Stream\PDFStream;
use PXP\PDF\Fpdf\Tree\PDFDocument;
use PXP\PDF\Fpdf\Tree\PDFObjectNode;

/**
 * Processes pages incrementally, building PDF structure on-the-fly
 * without accumulating all pages in memory.
 *
 * Key optimization: Page data is added directly to the merged document
 * and freed immediately after processing.
 */
final class IncrementalPageProcessor
{
    private PDFDocument $targetDoc;
    private PDFObjectNode $pagesNode;
    private KidsArray $kidsArray;
    private int $pageCount = 0;
    private int $nextObjectNumber;
    private LoggerInterface $logger;

    /** @var null|array<float> */
    private ?array $defaultMediaBox = null;

    /** @var array<int, int> Global object mapping to reuse copied objects across pages */
    private array $globalObjectMap = [];

    /** @var null|callable Stream writer function for immediate writes */
    private $streamWriter;

    /** @var int Current byte offset in output stream */
    private int $currentOffset = 0;

    /** @var array<int, int> Object number => byte offset mapping for xref table */
    private array $objectOffsets = [];

    public function __construct(
        PDFDocument $targetDoc,
        LoggerInterface $logger,
        int $startObjectNumber = 2,
        ?callable $streamWriter = null,
        int $startOffset = 0
    ) {
        $this->targetDoc        = $targetDoc;
        $this->logger           = $logger;
        $this->nextObjectNumber = $startObjectNumber;
        $this->streamWriter     = $streamWriter;
        $this->currentOffset    = $startOffset;

        // Initialize Pages tree (object 1) - empty at first
        $this->initializePagesTree();
    }

    /**
     * Process and append a single page to the merged document.
     *
     * This method:
     * 1. Copies page resources
     * 2. Copies page content streams
     * 3. Creates page dictionary
     * 4. Appends to Pages tree
     * 5. Immediately frees page data
     *
     * @param array{pageDict: PDFDictionary, content: string, resources: null|PDFDictionary, mediaBox: null|array<float>, hasMediaBox: bool, sourcePath?: string, sourceDoc?: PDFDocument} $pageData
     * @param array<string, array<string, string>>                                                                                                                                         $resourceNameMap Global resource name mapping
     * @param array<string, PDFDocument>                                                                                                                                                   $sourceDocCache  Cache of parsed source documents
     */
    public function appendPage(
        array $pageData,
        array &$resourceNameMap,
        array &$sourceDocCache
    ): void {
        $this->logger->debug('Processing page incrementally', [
            'page_number'        => $this->pageCount + 1,
            'next_object_number' => $this->nextObjectNumber,
        ]);

        // Set default MediaBox from first page
        if ($this->pageCount === 0 && $pageData['mediaBox'] !== null) {
            $this->defaultMediaBox = $pageData['mediaBox'];
            $pagesDict             = $this->pagesNode->getValue();

            if ($pagesDict instanceof PDFDictionary) {
                $pagesDict->addEntry('/MediaBox', new MediaBoxArray($this->defaultMediaBox));
            }
        }

        // Allocate object numbers
        $resourcesObjNum = $this->nextObjectNumber++;

        // Copy resources for this page
        $localResourceMap = [];

        if ($pageData['resources'] !== null) {
            $sourceDoc = $this->resolveSourceDoc($pageData, $sourceDocCache);

            $this->copyResourcesForPage(
                $pageData['resources'],
                $sourceDoc,
                $resourcesObjNum,
                $resourceNameMap,
                $localResourceMap,
                $this->nextObjectNumber,
            );
        } else {
            // Empty resources - write immediately if streaming
            $emptyResources = new ResourcesDictionary;

            if ($this->streamWriter !== null) {
                $this->objectOffsets[$resourcesObjNum] = $this->currentOffset;
                $this->currentOffset                   = $this->targetDoc->writeObjectToStream(
                    $emptyResources,
                    $resourcesObjNum,
                    $this->streamWriter,
                    $this->currentOffset,
                );
            } else {
                $this->targetDoc->addObject($emptyResources, $resourcesObjNum);
            }
        }

        // Copy content streams
        $contentObjNums = $this->copyContentStreams(
            $pageData,
            $localResourceMap,
            $sourceDocCache,
        );

        // Create page dictionary - write immediately if streaming
        $pageObjNum = $this->nextObjectNumber++;

        if ($this->streamWriter !== null) {
            $this->writePageObjectToStream(
                $pageObjNum,
                $resourcesObjNum,
                $contentObjNums,
                $pageData,
            );
        } else {
            $this->createPageObject(
                $pageObjNum,
                $resourcesObjNum,
                $contentObjNums,
                $pageData,
            );
        }

        // Append to Kids array
        $this->kidsArray->addPage($pageObjNum);
        $this->pageCount++;

        // Update /Count in Pages dictionary
        $pagesDict = $this->pagesNode->getValue();

        if ($pagesDict instanceof PDFDictionary) {
            $pagesDict->addEntry('/Count', new PDFNumber($this->pageCount));
        }

        $this->logger->debug('Page appended incrementally', [
            'page_number'        => $this->pageCount,
            'page_object_number' => $pageObjNum,
            'total_pages_so_far' => $this->pageCount,
        ]);

        // CRITICAL: Free page data immediately to release memory
        unset($pageData);
    }

    /**
     * Finalize the merged document by creating catalog.
     */
    public function finalize(): void
    {
        // Create catalog
        $catalogObjNum = $this->nextObjectNumber++;
        $catalog       = new CatalogDictionary;
        $catalog->setPages(1); // Pages object is always 1

        $catalogNode = $this->targetDoc->addObject($catalog, $catalogObjNum);
        $this->targetDoc->setRoot($catalogNode);

        $this->logger->info('Incremental merge finalized', [
            'total_pages'           => $this->pageCount,
            'catalog_object_number' => $catalogObjNum,
        ]);
    }

    /**
     * Get current page count.
     */
    public function getPageCount(): int
    {
        return $this->pageCount;
    }

    /**
     * Get next object number for external use.
     */
    public function getNextObjectNumber(): int
    {
        return $this->nextObjectNumber;
    }

    /**
     * Get current byte offset (for streaming mode).
     */
    public function getCurrentOffset(): int
    {
        return $this->currentOffset;
    }

    /**
     * Get object offsets for xref table (streaming mode).
     *
     * @return array<int, int>
     */
    public function getObjectOffsets(): array
    {
        return $this->objectOffsets;
    }

    /**
     * Initialize empty Pages tree structure that will be populated incrementally.
     */
    private function initializePagesTree(): void
    {
        $pagesDict = new PDFDictionary;
        $pagesDict->addEntry('/Type', new PDFName('Pages'));

        $this->kidsArray = new KidsArray;
        $pagesDict->addEntry('/Kids', $this->kidsArray);
        $pagesDict->addEntry('/Count', new PDFNumber(0));

        // MediaBox will be set when first page is processed
        // Default to A4 if no pages provide MediaBox
        $this->defaultMediaBox = [0.0, 0.0, 595.28, 841.89];

        $this->pagesNode = $this->targetDoc->addObject($pagesDict, 1);

        $this->logger->debug('Initialized empty Pages tree', [
            'pages_object_number' => 1,
        ]);
    }

    /**
     * Resolve source document from page data or cache.
     *
     * @param array{sourceDoc?: PDFDocument, sourcePath?: string} $pageData
     * @param array<string, PDFDocument>                          $sourceDocCache
     */
    private function resolveSourceDoc(array $pageData, array &$sourceDocCache): PDFDocument
    {
        if (isset($pageData['sourceDoc']) && $pageData['sourceDoc'] instanceof PDFDocument) {
            return $pageData['sourceDoc'];
        }

        $sourcePath = $pageData['sourcePath'] ?? null;

        if ($sourcePath !== null && isset($sourceDocCache[$sourcePath])) {
            return $sourceDocCache[$sourcePath];
        }

        throw new FpdfException('Cannot resolve source document for page');
    }

    /**
     * Copy resources for a page with full recursive object copying.
     *
     * @param array<string, array<string, string>> $resourceNameMap
     * @param array<string, string>                $localResourceMap
     */
    private function copyResourcesForPage(
        PDFDictionary $resourcesDict,
        PDFDocument $sourceDoc,
        int $resourcesObjNum,
        array &$resourceNameMap,
        array &$localResourceMap,
        int &$nextObjectNumber
    ): void {
        $newResources = new ResourcesDictionary;
        // Use global objectMap to reuse copied objects across pages
        // This prevents copying the same font/xobject multiple times
        $docId = spl_object_id($sourceDoc);

        // Copy fonts with full object copying
        $fonts = $resourcesDict->getEntry('/Font');

        // Dereference if it's a reference
        if ($fonts instanceof PDFReference) {
            $fontsNode = $sourceDoc->getObject($fonts->getObjectNumber());

            if ($fontsNode !== null) {
                $fonts = $fontsNode->getValue();
            }
        }

        if ($fonts instanceof PDFDictionary) {
            $newFonts = new PDFDictionary;

            foreach ($fonts->getAllEntries() as $fontName => $fontRef) {
                $uniqueName = $this->getUniqueResourceName(
                    ltrim($fontName, '/'),
                    $resourceNameMap,
                    'Font',
                );
                $localResourceMap['Font'][$fontName] = $uniqueName;

                // Copy font object recursively
                if ($fontRef instanceof PDFReference) {
                    $oldObjNum = $fontRef->getObjectNumber();
                    $mapKey    = "{$docId}:{$oldObjNum}";

                    // Check if already copied
                    if (isset($this->globalObjectMap[$mapKey])) {
                        $newFonts->addEntry('/' . $uniqueName, new PDFReference($this->globalObjectMap[$mapKey]));
                    } else {
                        $fontNode = $sourceDoc->getObject($oldObjNum);

                        if ($fontNode !== null) {
                            $fontObj                        = $fontNode->getValue();
                            $copyingObjects                 = [];
                            $newObjNum                      = $nextObjectNumber;
                            $this->globalObjectMap[$mapKey] = $newObjNum;
                            $nextObjectNumber++;

                            $copiedFont = $this->copyObjectWithReferences($fontObj, $sourceDoc, $this->targetDoc, $nextObjectNumber, $this->globalObjectMap, $copyingObjects, 0, 20);

                            // Write immediately if streaming
                            if ($this->streamWriter !== null) {
                                $this->objectOffsets[$newObjNum] = $this->currentOffset;
                                $this->currentOffset             = $this->targetDoc->writeObjectToStream(
                                    $copiedFont,
                                    $newObjNum,
                                    $this->streamWriter,
                                    $this->currentOffset,
                                );
                            } else {
                                $this->targetDoc->addObject($copiedFont, $newObjNum);
                            }
                            $newFonts->addEntry('/' . $uniqueName, new PDFReference($newObjNum));
                        }
                    }
                }
            }

            if (count($newFonts->getAllEntries()) > 0) {
                $newResources->addEntry('/Font', $newFonts);
            }
        }

        // Copy XObjects with full object copying
        $xobjects = $resourcesDict->getEntry('/XObject');

        // Dereference if it's a reference
        if ($xobjects instanceof PDFReference) {
            $xobjectsNode = $sourceDoc->getObject($xobjects->getObjectNumber());

            if ($xobjectsNode !== null) {
                $xobjects = $xobjectsNode->getValue();
            }
        }

        if ($xobjects instanceof PDFDictionary) {
            $newXObjects = new PDFDictionary;

            foreach ($xobjects->getAllEntries() as $xobjName => $xobjRef) {
                $uniqueName = $this->getUniqueResourceName(
                    ltrim($xobjName, '/'),
                    $resourceNameMap,
                    'XObject',
                );
                $localResourceMap['XObject'][$xobjName] = $uniqueName;

                // Copy XObject recursively
                if ($xobjRef instanceof PDFReference) {
                    $oldObjNum = $xobjRef->getObjectNumber();
                    $mapKey    = "{$docId}:{$oldObjNum}";

                    // Check if already copied
                    if (isset($this->globalObjectMap[$mapKey])) {
                        $newXObjects->addEntry('/' . $uniqueName, new PDFReference($this->globalObjectMap[$mapKey]));
                    } else {
                        $xobjNode = $sourceDoc->getObject($oldObjNum);

                        if ($xobjNode !== null) {
                            $xobjObj                        = $xobjNode->getValue();
                            $copyingObjects                 = [];
                            $newObjNum                      = $nextObjectNumber;
                            $this->globalObjectMap[$mapKey] = $newObjNum;
                            $nextObjectNumber++;

                            $copiedXObj = $this->copyObjectWithReferences($xobjObj, $sourceDoc, $this->targetDoc, $nextObjectNumber, $this->globalObjectMap, $copyingObjects, 0, 20);

                            // Write immediately if streaming
                            if ($this->streamWriter !== null) {
                                $this->objectOffsets[$newObjNum] = $this->currentOffset;
                                $this->currentOffset             = $this->targetDoc->writeObjectToStream(
                                    $copiedXObj,
                                    $newObjNum,
                                    $this->streamWriter,
                                    $this->currentOffset,
                                );
                            } else {
                                $this->targetDoc->addObject($copiedXObj, $newObjNum);
                            }
                            $newXObjects->addEntry('/' . $uniqueName, new PDFReference($newObjNum));
                        }
                    }
                }
            }

            if (count($newXObjects->getAllEntries()) > 0) {
                $newResources->addEntry('/XObject', $newXObjects);
            }
        }

        // Copy ExtGState with full object copying
        $extgstates = $resourcesDict->getEntry('/ExtGState');

        // Dereference if it's a reference
        if ($extgstates instanceof PDFReference) {
            $extgstatesNode = $sourceDoc->getObject($extgstates->getObjectNumber());

            if ($extgstatesNode !== null) {
                $extgstates = $extgstatesNode->getValue();
            }
        }

        if ($extgstates instanceof PDFDictionary) {
            $newExtGStates = new PDFDictionary;

            foreach ($extgstates->getAllEntries() as $gsName => $gsRef) {
                $uniqueName = $this->getUniqueResourceName(
                    ltrim($gsName, '/'),
                    $resourceNameMap,
                    'ExtGState',
                );
                $localResourceMap['ExtGState'][$gsName] = $uniqueName;

                // Copy ExtGState recursively
                if ($gsRef instanceof PDFReference) {
                    $oldObjNum = $gsRef->getObjectNumber();
                    $mapKey    = "{$docId}:{$oldObjNum}";

                    // Check if already copied
                    if (isset($this->globalObjectMap[$mapKey])) {
                        $newExtGStates->addEntry('/' . $uniqueName, new PDFReference($this->globalObjectMap[$mapKey]));
                    } else {
                        $gsNode = $sourceDoc->getObject($oldObjNum);

                        if ($gsNode !== null) {
                            $gsObj                          = $gsNode->getValue();
                            $copyingObjects                 = [];
                            $newObjNum                      = $nextObjectNumber;
                            $this->globalObjectMap[$mapKey] = $newObjNum;
                            $nextObjectNumber++;

                            $copiedGS = $this->copyObjectWithReferences($gsObj, $sourceDoc, $this->targetDoc, $nextObjectNumber, $this->globalObjectMap, $copyingObjects, 0, 20);

                            // Write immediately if streaming
                            if ($this->streamWriter !== null) {
                                $this->objectOffsets[$newObjNum] = $this->currentOffset;
                                $this->currentOffset             = $this->targetDoc->writeObjectToStream(
                                    $copiedGS,
                                    $newObjNum,
                                    $this->streamWriter,
                                    $this->currentOffset,
                                );
                            } else {
                                $this->targetDoc->addObject($copiedGS, $newObjNum);
                            }
                            $newExtGStates->addEntry('/' . $uniqueName, new PDFReference($newObjNum));
                        }
                    }
                }
            }

            if (count($newExtGStates->getAllEntries()) > 0) {
                $newResources->addEntry('/ExtGState', $newExtGStates);
            }
        }

        // Write resources immediately if streaming, otherwise add to document
        if ($this->streamWriter !== null) {
            $this->objectOffsets[$resourcesObjNum] = $this->currentOffset;
            $this->currentOffset                   = $this->targetDoc->writeObjectToStream(
                $newResources,
                $resourcesObjNum,
                $this->streamWriter,
                $this->currentOffset,
            );
        } else {
            $this->targetDoc->addObject($newResources, $resourcesObjNum);
        }
    }

    /**
     * Get unique resource name to avoid conflicts across merged PDFs.
     *
     * @param array<string, array<string, string>> $resourceNameMap
     */
    private function getUniqueResourceName(
        string $name,
        array &$resourceNameMap,
        string $type
    ): string {
        $key = $type . '_' . $name;

        if (!isset($resourceNameMap[$type][$name])) {
            $counter    = 1;
            $uniqueName = $name;

            while (isset($resourceNameMap[$type][$uniqueName])) {
                $uniqueName = $name . '_' . $counter;
                $counter++;
            }

            $resourceNameMap[$type][$name] = $uniqueName;
        }

        return $resourceNameMap[$type][$name];
    }

    /**
     * Copy content streams with minimal memory usage.
     *
     * @param array{content: string, pageDict: PDFDictionary, sourcePath?: string} $pageData
     * @param array<string, array<string, string>>                                 $localResourceMap
     * @param array<string, PDFDocument>                                           $sourceDocCache
     *
     * @return array<int> Content object numbers
     */
    private function copyContentStreams(
        array $pageData,
        array $localResourceMap,
        array &$sourceDocCache
    ): array {
        $contentObjNum = $this->nextObjectNumber++;

        // Get content string
        $content = $pageData['content'] ?? '';

        // Replace resource names if needed
        if (!empty($localResourceMap)) {
            foreach ($localResourceMap as $resType => $map) {
                foreach ($map as $oldName => $newName) {
                    // Use regex with word boundaries to avoid partial matches
                    // e.g., /F1 should not match inside /F11
                    $escapedOldName = preg_quote(ltrim($oldName, '/'), '/');
                    $content        = preg_replace(
                        '/\/' . $escapedOldName . '(?![a-zA-Z0-9_])/',
                        '/' . $newName,
                        $content,
                    );
                }
            }
        }

        // Create stream with FlateDecode compression
        $streamDict = new PDFDictionary;
        $stream     = new PDFStream($streamDict, $content, false);
        $stream->addFilter('FlateDecode');

        // Write immediately if streaming
        if ($this->streamWriter !== null) {
            $this->objectOffsets[$contentObjNum] = $this->currentOffset;
            $this->currentOffset                 = $this->targetDoc->writeObjectToStream(
                $stream,
                $contentObjNum,
                $this->streamWriter,
                $this->currentOffset,
            );
        } else {
            $this->targetDoc->addObject($stream, $contentObjNum);
        }

        // Free content immediately
        unset($content);

        return [$contentObjNum];
    }

    /**
     * Create page object and add to document.
     *
     * @param array<int>                                                                     $contentObjNums
     * @param array{hasMediaBox: bool, mediaBox: null|array<float>, pageDict: PDFDictionary} $pageData
     */
    private function createPageObject(
        int $pageObjNum,
        int $resourcesObjNum,
        array $contentObjNums,
        array $pageData
    ): void {
        $newPageDict = new PageDictionary;
        $newPageDict->setParent($this->pagesNode);
        $newPageDict->setResources($resourcesObjNum);

        // Set contents (single stream or array)
        if (count($contentObjNums) === 1) {
            $newPageDict->setContents($contentObjNums[0]);
        } else {
            // Multiple content streams - create array
            $contentsArray = new PDFArray;

            foreach ($contentObjNums as $num) {
                $contentsArray->add(new PDFReference($num));
            }
            $newPageDict->addEntry('/Contents', $contentsArray);
        }

        // Set MediaBox if page has its own OR if it differs from default
        // This ensures portrait/landscape differences are preserved
        if ($pageData['mediaBox'] !== null) {
            $needsOwnMediaBox = $pageData['hasMediaBox'] ||
                $this->defaultMediaBox === null ||
                $pageData['mediaBox'] !== $this->defaultMediaBox;

            if ($needsOwnMediaBox) {
                $newPageDict->setMediaBox($pageData['mediaBox']);
            }
        }

        // Copy important page-level entries
        $sourcePageDict = $pageData['pageDict'];
        $this->copyPageLevelEntries($sourcePageDict, $newPageDict);

        $this->targetDoc->addObject($newPageDict, $pageObjNum);
    }

    /**
     * Write page object directly to stream (streaming mode).
     *
     * @param array<int>                                                                     $contentObjNums
     * @param array{hasMediaBox: bool, mediaBox: null|array<float>, pageDict: PDFDictionary} $pageData
     */
    private function writePageObjectToStream(
        int $pageObjNum,
        int $resourcesObjNum,
        array $contentObjNums,
        array $pageData
    ): void {
        $newPageDict = new PageDictionary;
        $newPageDict->setParent($this->pagesNode);
        $newPageDict->setResources($resourcesObjNum);

        // Set contents (single stream or array)
        if (count($contentObjNums) === 1) {
            $newPageDict->setContents($contentObjNums[0]);
        } else {
            // Multiple content streams - create array
            $contentsArray = new PDFArray;

            foreach ($contentObjNums as $num) {
                $contentsArray->add(new PDFReference($num));
            }
            $newPageDict->addEntry('/Contents', $contentsArray);
        }

        // Set MediaBox if page has its own OR if it differs from default
        // This ensures portrait/landscape differences are preserved
        if ($pageData['mediaBox'] !== null) {
            $needsOwnMediaBox = $pageData['hasMediaBox'] ||
                $this->defaultMediaBox === null ||
                $pageData['mediaBox'] !== $this->defaultMediaBox;

            if ($needsOwnMediaBox) {
                $newPageDict->setMediaBox($pageData['mediaBox']);
            }
        }

        // Copy important page-level entries
        $sourcePageDict = $pageData['pageDict'];
        $this->copyPageLevelEntries($sourcePageDict, $newPageDict);

        // Write immediately to stream
        $this->objectOffsets[$pageObjNum] = $this->currentOffset;
        $this->currentOffset              = $this->targetDoc->writeObjectToStream(
            $newPageDict,
            $pageObjNum,
            $this->streamWriter,
            $this->currentOffset,
        );
    }

    /**
     * Copy important page-level entries that affect rendering.
     */
    private function copyPageLevelEntries(
        PDFDictionary $sourcePageDict,
        PageDictionary $targetPageDict
    ): void {
        // Copy /Rotate if present
        $rotate = $sourcePageDict->getEntry('/Rotate');

        if ($rotate instanceof PDFNumber) {
            $targetPageDict->setRotate((int) $rotate->getValue());
        }

        // Copy /CropBox if present
        $cropBox = $sourcePageDict->getEntry('/CropBox');

        if ($cropBox !== null) {
            $targetPageDict->addEntry('/CropBox', $cropBox);
        }

        // Copy /BleedBox if present
        $bleedBox = $sourcePageDict->getEntry('/BleedBox');

        if ($bleedBox !== null) {
            $targetPageDict->addEntry('/BleedBox', $bleedBox);
        }

        // Copy /TrimBox if present
        $trimBox = $sourcePageDict->getEntry('/TrimBox');

        if ($trimBox !== null) {
            $targetPageDict->addEntry('/TrimBox', $trimBox);
        }

        // Copy /ArtBox if present
        $artBox = $sourcePageDict->getEntry('/ArtBox');

        if ($artBox !== null) {
            $targetPageDict->addEntry('/ArtBox', $artBox);
        }
    }

    /**
     * Recursively copy an object and resolve all references.
     *
     * @param array<int, int>  $objectMap
     * @param array<int, true> $copyingObjects
     * @param int              $depth          Current recursion depth
     * @param int              $maxDepth       Maximum allowed recursion depth
     */
    private function copyObjectWithReferences(
        PDFObjectInterface $obj,
        PDFDocument $sourceDoc,
        PDFDocument $targetDoc,
        int &$nextObjectNumber,
        array &$objectMap = [],
        array &$copyingObjects = [],
        int $depth = 0,
        int $maxDepth = 10
    ): PDFObjectInterface {
        // Check depth limit to prevent stack overflow and memory exhaustion
        if ($depth >= $maxDepth) {
            // At max depth, still try to remap references if already in objectMap
            if ($obj instanceof PDFReference) {
                $oldObjNum = $obj->getObjectNumber();
                $docId     = spl_object_id($sourceDoc);
                $mapKey    = "{$docId}:{$oldObjNum}";

                // If already remapped, return new reference
                if (isset($objectMap[$mapKey])) {
                    return new PDFReference($objectMap[$mapKey]);
                }

                // Otherwise return original (unavoidable at max depth)
                return $obj;
            }

            return $obj;
        }

        $docId = spl_object_id($sourceDoc);

        if ($obj instanceof PDFReference) {
            $oldObjNum = $obj->getObjectNumber();
            $mapKey    = "{$docId}:{$oldObjNum}";

            if (isset($objectMap[$mapKey])) {
                return new PDFReference($objectMap[$mapKey]);
            }

            if (isset($copyingObjects[$oldObjNum])) {
                if (isset($objectMap[$mapKey])) {
                    return new PDFReference($objectMap[$mapKey]);
                }
                $newObjNum          = $nextObjectNumber;
                $objectMap[$mapKey] = $newObjNum;
                $nextObjectNumber++;

                return new PDFReference($newObjNum);
            }

            $refNode = $sourceDoc->getObject($oldObjNum);

            if ($refNode !== null) {
                $refObj = $refNode->getValue();

                $newObjNum          = $nextObjectNumber;
                $objectMap[$mapKey] = $newObjNum;
                $nextObjectNumber++;

                $copyingObjects[$oldObjNum] = true;

                $copiedRefObj = $this->copyObjectWithReferences($refObj, $sourceDoc, $targetDoc, $nextObjectNumber, $objectMap, $copyingObjects, $depth + 1, $maxDepth);

                // Write immediately if streaming
                if ($this->streamWriter !== null) {
                    $this->objectOffsets[$newObjNum] = $this->currentOffset;
                    $this->currentOffset             = $targetDoc->writeObjectToStream(
                        $copiedRefObj,
                        $newObjNum,
                        $this->streamWriter,
                        $this->currentOffset,
                    );
                } else {
                    $targetDoc->addObject($copiedRefObj, $newObjNum);
                }

                unset($copyingObjects[$oldObjNum]);

                return new PDFReference($newObjNum);
            }

            return $obj;
        }

        if ($obj instanceof PDFDictionary) {
            $newDict = new PDFDictionary;

            foreach ($obj->getAllEntries() as $key => $value) {
                if ($value instanceof PDFObjectInterface) {
                    $newDict->addEntry($key, $this->copyObjectWithReferences($value, $sourceDoc, $targetDoc, $nextObjectNumber, $objectMap, $copyingObjects, $depth + 1, $maxDepth));
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
                    $newArray->add($this->copyObjectWithReferences($item, $sourceDoc, $targetDoc, $nextObjectNumber, $objectMap, $copyingObjects, $depth + 1, $maxDepth));
                } else {
                    $newArray->add($item);
                }
            }

            return $newArray;
        }

        if ($obj instanceof PDFStream) {
            $streamDict = $obj->getDictionary();
            // Copy stream dictionary first
            $copiedDict = $this->copyObjectWithReferences($streamDict, $sourceDoc, $targetDoc, $nextObjectNumber, $objectMap, $copyingObjects, $depth + 1, $maxDepth);

            if (!$copiedDict instanceof PDFDictionary) {
                $copiedDict = new PDFDictionary;
            }

            // Copy encoded stream data directly
            $encodedData = $obj->getEncodedData();
            $newStream   = new PDFStream($copiedDict, $encodedData, true);
            $newStream->setEncodedData($encodedData);

            return $newStream;
        }

        // Default: return original object
        return $obj;
    }
}
