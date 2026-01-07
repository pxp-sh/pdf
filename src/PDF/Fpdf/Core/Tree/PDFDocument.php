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
namespace PXP\PDF\Fpdf\Core\Tree;

use function array_merge;
use function array_values;
use function count;
use function implode;
use function ksort;
use function preg_match;
use function reset;
use function strlen;
use function strpos;
use function strrpos;
use function substr;
use Exception;
use PXP\PDF\Fpdf\Core\Object\Base\PDFArray;
use PXP\PDF\Fpdf\Core\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Core\Object\Base\PDFName;
use PXP\PDF\Fpdf\Core\Object\Base\PDFNumber;
use PXP\PDF\Fpdf\Core\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Core\Object\PDFObjectInterface;
use PXP\PDF\Fpdf\Core\Xref\PDFXrefTable;
use PXP\PDF\Fpdf\Exceptions\Exception\FpdfException;
use PXP\PDF\Fpdf\IO\FileIO;
use PXP\PDF\Fpdf\IO\FileReaderInterface;
use ReflectionClass;

/**
 * Root document class representing a complete PDF as a tree structure.
 */
final class PDFDocument
{
    private PDFHeader $pdfHeader;
    private readonly PDFXrefTable $pdfXrefTable;
    private readonly PDFTrailer $pdfTrailer;
    private ?PDFObjectNode $pdfObjectNode = null;

    /**
     * Parse PDF from file.
     */
    public static function parseFromFile(string $filePath, ?FileReaderInterface $fileReader = null): self
    {
        if (!$fileReader instanceof FileReaderInterface) {
            $fileReader = new FileIO;
        }

        $content = $fileReader->readFile($filePath);

        return self::parseFromString($content);
    }

    /**
     * Parse PDF from string content.
     * This is a basic implementation - full parsing will be handled by PDFParser.
     */
    public static function parseFromString(string $content): self
    {
        $document = new self;

        // Parse header
        $header = PDFHeader::parse($content);

        if ($header instanceof PDFHeader) {
            $document->pdfHeader = $header;
        }

        // Basic xref parsing (full parsing will be in PDFParser)
        $xrefPos = strrpos($content, 'xref');

        if ($xrefPos !== false) {
            $xrefEnd = strpos($content, 'trailer', $xrefPos);

            if ($xrefEnd !== false) {
                $xrefContent = substr($content, $xrefPos + 4, $xrefEnd - $xrefPos - 4);
                $document->pdfXrefTable->parseFromString($xrefContent);
            }
        }

        // Parse trailer
        $trailerPos = strrpos($content, 'trailer');

        if ($trailerPos !== false) {
            $trailerSection = substr($content, $trailerPos);
            $rootMatch      = [];

            if (preg_match('/\/Root\s+(\d+)\s+(\d+)\s+R/', $trailerSection, $rootMatch)) {
                $rootObjNum = (int) $rootMatch[1];
                // Root will be set when objects are parsed
            }
        }

        return $document;
    }

    public function __construct(string $version = '1.3', private readonly ?PDFObjectRegistry $pdfObjectRegistry = new PDFObjectRegistry)
    {
        $this->pdfHeader    = new PDFHeader($version);
        $this->pdfXrefTable = new PDFXrefTable;
        $this->pdfTrailer   = new PDFTrailer;
    }

    public function getHeader(): PDFHeader
    {
        return $this->pdfHeader;
    }

    public function getXrefTable(): PDFXrefTable
    {
        return $this->pdfXrefTable;
    }

    public function getTrailer(): PDFTrailer
    {
        return $this->pdfTrailer;
    }

    public function getObjectRegistry(): PDFObjectRegistry
    {
        return $this->pdfObjectRegistry;
    }

    /**
     * Get an object by object number.
     */
    public function getObject(int $objectNumber): ?PDFObjectNode
    {
        return $this->pdfObjectRegistry->get($objectNumber);
    }

    /**
     * Add a new object to the document.
     */
    public function addObject(PDFObjectInterface $pdfObject, ?int $objectNumber = null): PDFObjectNode
    {
        if ($objectNumber === null) {
            $objectNumber = $this->pdfObjectRegistry->getNextObjectNumber();
        }

        $pdfObjectNode = new PDFObjectNode($objectNumber, $pdfObject);
        $this->pdfObjectRegistry->register($pdfObjectNode);

        return $pdfObjectNode;
    }

    /**
     * Remove an object from the document.
     */
    public function removeObject(int $objectNumber): void
    {
        $this->pdfObjectRegistry->remove($objectNumber);
        $this->pdfXrefTable->getEntry($objectNumber)?->setFree(true);
    }

    /**
     * Write an object directly to stream and track its offset WITHOUT storing in registry.
     * This enables true streaming where objects are written immediately and released from memory.
     *
     * @param callable $writer        Function that accepts string chunks to write
     * @param int      $currentOffset Current byte offset in the output stream
     *
     * @return int New offset after writing this object
     */
    public function writeObjectToStream(
        PDFObjectInterface $pdfObject,
        int $objectNumber,
        callable $writer,
        int $currentOffset
    ): int {
        $pdfObjectNode = new PDFObjectNode($objectNumber, $pdfObject);
        $objStr        = $pdfObjectNode . "\n";
        $writer($objStr);

        return $currentOffset + strlen($objStr);
    }

    /**
     * Get the root catalog object.
     */
    public function getRoot(): ?PDFObjectNode
    {
        return $this->pdfObjectNode;
    }

    /**
     * Set the root catalog object.
     */
    public function setRoot(PDFObjectNode $pdfObjectNode): void
    {
        $this->pdfObjectNode = $pdfObjectNode;
        $this->pdfTrailer->setRoot(new PDFReference($pdfObjectNode->getObjectNumber()));
    }

    /**
     * Check if any objects of the given type exist.
     */
    public function hasObjectsByType(string $type): bool
    {
        return count($this->getObjectsByType($type)) > 0;
    }

    /**
     * Get all objects with the matching Type field.
     * Only checks already-loaded objects to avoid memory issues.
     * For finding specific objects, prefer using direct references (e.g., Catalog → Pages).
     *
     * @return array<PDFObjectNode>
     */
    public function getObjectsByType(string $type): array
    {
        $objects = [];
        // Only check already-loaded objects, don't force-load all objects
        // This prevents memory explosion when lazy loading is enabled
        $loadedObjects = $this->pdfObjectRegistry->getLoadedObjects();

        foreach ($loadedObjects as $loadedObject) {
            $nodeType = $this->getObjectType($loadedObject);

            if ($nodeType === $type) {
                $objects[] = $loadedObject;
            }
        }

        return $objects;
    }

    /**
     * Get all pages in the document using hierarchical traversal with fallbacks.
     *
     * @param bool $deep If true, recursively traverse nested Pages objects
     *
     * @throws FpdfException If no pages are found
     *
     * @return array<PDFObjectNode> Array of Page nodes
     */
    public function getAllPages(bool $deep = true): array
    {
        // Primary: Try Root → Catalog → Pages (most efficient, uses direct references)
        $root = $this->getRoot();

        // If root isn't set but trailer has root reference, try to load it
        if (!$root instanceof PDFObjectNode) {
            $rootRef = $this->pdfTrailer->getRoot();

            if ($rootRef !== null) {
                $root = $this->getObject($rootRef->getObjectNumber());

                if ($root instanceof PDFObjectNode) {
                    $this->setRoot($root);
                }
            }
        }

        if ($root instanceof PDFObjectNode) {
            $rootDict = $root->getValue();

            if ($rootDict instanceof PDFDictionary) {
                $pagesRef = $rootDict->getEntry('/Pages');

                if ($pagesRef instanceof PDFReference) {
                    $pagesNode = $this->getObject($pagesRef->getObjectNumber());

                    if ($pagesNode instanceof PDFObjectNode) {
                        return $this->getAllPagesFromPages($pagesNode, $deep);
                    }
                }
            }
        }

        // Fallback 1: Try Catalog → Pages (only checks already-loaded objects)
        if ($this->hasObjectsByType('Catalog')) {
            $catalogs = $this->getObjectsByType('Catalog');
            $catalog  = reset($catalogs);

            if ($catalog !== false) {
                $catalogDict = $catalog->getValue();

                if ($catalogDict instanceof PDFDictionary) {
                    $pagesRef = $catalogDict->getEntry('/Pages');

                    if ($pagesRef instanceof PDFReference) {
                        $pagesNode = $this->getObject($pagesRef->getObjectNumber());

                        if ($pagesNode instanceof PDFObjectNode) {
                            return $this->getAllPagesFromPages($pagesNode, $deep);
                        }
                    }
                }
            }
        }

        // Fallback 2: If no Catalog, try to find Pages by loading objects from xref table
        // This handles cases where root isn't set but Pages objects exist
        $pagesNode = $this->getPages();

        if ($pagesNode instanceof PDFObjectNode) {
            return $this->getAllPagesFromPages($pagesNode, $deep);
        }

        // Fallback 2: If root can't be loaded, try to find Pages by checking xref table
        // This handles PDFs where root is compressed or in object streams
        $registryReflection = new ReflectionClass($this->pdfObjectRegistry);
        $xrefTableProp      = $registryReflection->getProperty('pdfXrefTable');
        $xrefTable          = $xrefTableProp->getValue($this->pdfObjectRegistry);

        if ($xrefTable !== null && !$root instanceof PDFObjectNode) {
            $allEntries = $xrefTable->getAllEntries();
            // Try to find Pages objects by loading objects that have /Kids (characteristic of Pages)
            // Limit to first 100 objects to avoid memory issues
            $checked      = 0;
            $maxChecks    = 100;
            $pagesObjects = [];

            foreach ($allEntries as $objectNumber => $entry) {
                if ($checked >= $maxChecks) {
                    break;
                }

                if ($entry->isFree()) {
                    continue;
                }

                // Try to load the object
                try {
                    $node = $this->getObject($objectNumber);

                    if ($node instanceof PDFObjectNode) {
                        $checked++;
                        $nodeValue = $node->getValue();

                        if ($nodeValue instanceof PDFDictionary) {
                            // Check if it has /Kids (Pages objects have Kids arrays)
                            $kids = $nodeValue->getEntry('/Kids');

                            if ($kids instanceof PDFArray) {
                                // This looks like a Pages object
                                $nodeType = $this->getObjectType($node);

                                if ($nodeType === 'Pages' || $nodeType === null) {
                                    // If type is Pages or missing (some PDFs omit Type), treat as Pages
                                    $pagesObjects[] = $node;
                                }
                            }
                        }
                    }
                } catch (Exception) {
                    // Skip objects that can't be loaded (e.g., compressed)
                    continue;
                }
            }

            if ($pagesObjects !== []) {
                $allPages = [];

                foreach ($pagesObjects as $pageObject) {
                    $pages    = $this->getAllPagesFromPages($pageObject, $deep);
                    $allPages = array_merge($allPages, $pages);
                }

                if ($allPages !== []) {
                    return $allPages;
                }
            }
        }

        // Fallback 3: If no Catalog, find all Pages objects and merge their kids recursively
        // Only checks already-loaded objects to avoid memory explosion
        if ($this->hasObjectsByType('Pages')) {
            $pagesObjects = $this->getObjectsByType('Pages');
            $allPages     = [];

            foreach ($pagesObjects as $pageObject) {
                $pages    = $this->getAllPagesFromPages($pageObject, $deep);
                $allPages = array_merge($allPages, $pages);
            }

            if ($allPages !== []) {
                return $allPages;
            }
        }

        // Fallback 4: If no Pages objects and root is compressed, try to find Page objects by checking xref table
        // This handles PDFs where Pages structure can't be traversed due to compressed root
        // Only run if root is null (compressed) and we haven't found any pages
        // Get xref table if not already available
        if (!$root instanceof PDFObjectNode) {
            if ($xrefTable === null) {
                $registryReflection = new ReflectionClass($this->pdfObjectRegistry);
                $xrefTableProp      = $registryReflection->getProperty('pdfXrefTable');
                $xrefTable          = $xrefTableProp->getValue($this->pdfObjectRegistry);
            }

            if ($xrefTable !== null) {
                $allEntries  = $xrefTable->getAllEntries();
                $pageObjects = [];
                $checked     = 0;
                $maxChecks   = 200; // Check more objects to find pages

                foreach ($allEntries as $objectNumber => $entry) {
                    if ($checked >= $maxChecks) {
                        break;
                    }

                    if ($entry->isFree()) {
                        continue;
                    }

                    try {
                        $node = $this->getObject($objectNumber);

                        if ($node instanceof PDFObjectNode) {
                            $checked++;
                            $nodeValue = $node->getValue();

                            if ($nodeValue instanceof PDFDictionary) {
                                $nodeType = $this->getObjectType($node);

                                // Only include if Type is explicitly Page (be very strict)
                                if ($nodeType === 'Page') {
                                    $pageObjects[] = $node;
                                }
                            }
                        }
                    } catch (Exception) {
                        // Skip objects that can't be loaded (e.g., compressed)
                        continue;
                    }
                }

                if ($pageObjects !== []) {
                    return array_values($pageObjects);
                }
            }
        }

        // Fallback 5: If no Pages objects, find all Page objects directly
        // Only checks already-loaded objects to avoid memory explosion
        if ($this->hasObjectsByType('Page')) {
            $pages = $this->getObjectsByType('Page');

            return array_values($pages);
        }

        // Error: No pages found
        throw new FpdfException('No pages found in PDF document');
    }

    /**
     * Get the pages object.
     */
    public function getPages(): ?PDFObjectNode
    {
        $root = $this->getRoot();

        if (!$root instanceof PDFObjectNode) {
            // Fallback: Try to find Pages objects directly
            if ($this->hasObjectsByType('Pages')) {
                $pagesObjects = $this->getObjectsByType('Pages');

                return reset($pagesObjects) ?: null;
            }

            return null;
        }

        $pdfObject = $root->getValue();

        if (!$pdfObject instanceof PDFDictionary) {
            return null;
        }

        $pagesRef = $pdfObject->getEntry('/Pages');

        if (!$pagesRef instanceof PDFReference) {
            return null;
        }

        return $this->getObject($pagesRef->getObjectNumber());
    }

    /**
     * Get a specific page by page number (1-based).
     */
    public function getPage(int $pageNumber): ?PDFObjectNode
    {
        try {
            $allPages  = $this->getAllPages(true);
            $pageIndex = $pageNumber - 1;

            if ($pageIndex >= 0 && $pageIndex < count($allPages)) {
                return $allPages[$pageIndex];
            }
        } catch (FpdfException) {
            // getAllPages failed, try fallback: find Pages objects and traverse them
            // This handles PDFs where normal traversal fails due to compressed root
            $reflectionClass = new ReflectionClass($this->pdfObjectRegistry);
            $xrefTableProp   = $reflectionClass->getProperty('pdfXrefTable');
            $xrefTable       = $xrefTableProp->getValue($this->pdfObjectRegistry);

            if ($xrefTable !== null) {
                // First try to find Pages objects (more reliable than finding Page objects directly)
                $allEntries   = $xrefTable->getAllEntries();
                $pagesObjects = [];
                $checked      = 0;
                $maxChecks    = 300; // Check more objects to find Pages

                foreach ($allEntries as $objectNumber => $entry) {
                    if ($checked >= $maxChecks) {
                        break;
                    }

                    if ($entry->isFree()) {
                        continue;
                    }

                    try {
                        $node = $this->getObject($objectNumber);

                        if ($node instanceof PDFObjectNode) {
                            $checked++;
                            $nodeValue = $node->getValue();

                            if ($nodeValue instanceof PDFDictionary) {
                                $nodeType = $this->getObjectType($node);

                                if ($nodeType === 'Pages') {
                                    $pagesObjects[] = $node;
                                }
                            }
                        }
                    } catch (Exception) {
                        continue;
                    }
                }

                // If we found Pages objects, try to identify the root Pages object
                // The root Pages object is typically the one with the highest /Count or referenced from trailer
                if ($pagesObjects !== []) {
                    // Try to find the root Pages by checking which one has the highest /Count
                    $rootPagesNode = null;
                    $maxCount      = 0;

                    foreach ($pagesObjects as $pageObject) {
                        try {
                            $pagesDict = $pageObject->getValue();

                            if ($pagesDict instanceof PDFDictionary) {
                                $countEntry = $pagesDict->getEntry('/Count');

                                if ($countEntry instanceof PDFNumber) {
                                    $count = (int) $countEntry->getValue();

                                    if ($count > $maxCount) {
                                        $maxCount      = $count;
                                        $rootPagesNode = $pageObject;
                                    }
                                }
                            }
                        } catch (Exception) {
                            continue;
                        }
                    }

                    // If we found a root Pages object, traverse it
                    if ($rootPagesNode instanceof PDFObjectNode) {
                        try {
                            $allPages  = $this->getAllPagesFromPages($rootPagesNode, true);
                            $pageIndex = $pageNumber - 1;

                            if ($pageIndex >= 0 && $pageIndex < count($allPages)) {
                                return $allPages[$pageIndex];
                            }
                        } catch (Exception) {
                            // Fall through to Page objects search
                        }
                    }
                }

                // Last resort: Find Page objects directly (less reliable, order may be wrong)
                $pageObjects = [];
                $checked     = 0;
                $maxChecks   = 300; // Check more objects

                foreach ($allEntries as $objectNumber => $entry) {
                    if ($checked >= $maxChecks) {
                        break;
                    }

                    if ($entry->isFree()) {
                        continue;
                    }

                    try {
                        $node = $this->getObject($objectNumber);

                        if ($node instanceof PDFObjectNode) {
                            $checked++;
                            $nodeValue = $node->getValue();

                            if ($nodeValue instanceof PDFDictionary) {
                                $nodeType = $this->getObjectType($node);

                                if ($nodeType === 'Page') {
                                    $pageObjects[] = $node;
                                }
                            }
                        }
                    } catch (Exception) {
                        continue;
                    }
                }

                // Return the requested page if available
                $pageIndex = $pageNumber - 1;

                if ($pageIndex >= 0 && $pageIndex < count($pageObjects)) {
                    return $pageObjects[$pageIndex];
                }
            }
        }

        return null;
    }

    /**
     * Serialize the document to PDF format.
     */
    public function serialize(): string
    {
        // Backwards-compatible wrapper that collects the whole PDF into memory.
        $parts = [];
        $this->serializeToStream(static function (string $chunk) use (&$parts): void
        {
            $parts[] = $chunk;
        });

        return implode('', $parts);
    }

    /**
     * Stream-serializes the PDF by invoking the provided writer for each written chunk.
     * The writer should accept a single string argument containing a PDF fragment to write.
     */
    public function serializeToStream(callable $writer): void
    {
        $offsets = [];
        $offset  = 0;

        // Header
        $headerStr = (string) $this->pdfHeader;
        $writer($headerStr);
        $offset += strlen($headerStr);

        // Serialize all objects
        $objects = $this->pdfObjectRegistry->getAll();
        ksort($objects);

        foreach ($objects as $objectNumber => $node) {
            $offsets[$objectNumber] = $offset;
            $objStr                 = $node . "\n";
            $writer($objStr);
            $offset += strlen($objStr);
        }

        // Rebuild xref table
        $this->pdfXrefTable->rebuild($offsets);

        // Xref table
        $xrefOffset = $offset;
        $xrefStr    = $this->pdfXrefTable->serialize();
        $writer($xrefStr);
        $offset += strlen((string) $xrefStr);

        // Trailer
        $this->pdfTrailer->setSize($this->pdfObjectRegistry->getMaxObjectNumber() + 1);
        $trailerStr = $this->pdfTrailer->serialize($xrefOffset);
        $writer($trailerStr);
    }

    /**
     * Get the object type from a node's dictionary.
     */
    private function getObjectType(PDFObjectNode $pdfObjectNode): ?string
    {
        $pdfObject = $pdfObjectNode->getValue();

        if (!$pdfObject instanceof PDFDictionary) {
            return null;
        }

        $typeEntry = $pdfObject->getEntry('/Type');

        if ($typeEntry instanceof PDFName) {
            return $typeEntry->getName();
        }

        return null;
    }

    /**
     * Get all pages recursively from a Pages object.
     *
     * @param PDFObjectNode $pdfObjectNode The Pages object node
     * @param bool          $deep          If true, recursively traverse nested Pages objects
     *
     * @return array<PDFObjectNode> Array of Page nodes
     */
    private function getAllPagesFromPages(PDFObjectNode $pdfObjectNode, bool $deep = true): array
    {
        $pdfObject = $pdfObjectNode->getValue();

        if (!$pdfObject instanceof PDFDictionary) {
            return [];
        }

        $kids = $pdfObject->getEntry('/Kids');

        if (!$kids instanceof PDFArray) {
            return [];
        }

        if (!$deep) {
            // Return direct kids without recursion
            $directPages = [];

            foreach ($kids->getAll() as $kid) {
                if ($kid instanceof PDFReference) {
                    $kidNode = $this->getObject($kid->getObjectNumber());

                    if ($kidNode instanceof PDFObjectNode) {
                        $directPages[] = $kidNode;
                    }
                }
            }

            return $directPages;
        }

        // Recursive traversal
        $pages = [];

        foreach ($kids->getAll() as $kid) {
            if ($kid instanceof PDFReference) {
                $kidNode = $this->getObject($kid->getObjectNumber());

                if (!$kidNode instanceof PDFObjectNode) {
                    continue;
                }

                $kidType = $this->getObjectType($kidNode);

                if ($kidType === 'Pages') {
                    // Recursively get pages from nested Pages object
                    $pages = array_merge($pages, $this->getAllPagesFromPages($kidNode, true));
                } elseif ($kidType === 'Page') {
                    // Direct Page object
                    $pages[] = $kidNode;
                } else {
                    // If Type is missing or unknown, check if it has a Kids array (might be a Pages object without Type)
                    // or if it has Contents (might be a Page object without Type)
                    $kidValue = $kidNode->getValue();

                    if ($kidValue instanceof PDFDictionary) {
                        $kidKids = $kidValue->getEntry('/Kids');

                        if ($kidKids instanceof PDFArray) {
                            // Has Kids array, treat as Pages object
                            $pages = array_merge($pages, $this->getAllPagesFromPages($kidNode, true));
                        } elseif ($kidValue->hasEntry('/Contents') || $kidValue->hasEntry('/MediaBox')) {
                            // Has Contents or MediaBox, likely a Page object
                            $pages[] = $kidNode;
                        }
                    }
                }
            }
        }

        return $pages;
    }
}
