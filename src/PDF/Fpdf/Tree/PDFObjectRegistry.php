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

namespace PXP\PDF\Fpdf\Tree;

use PXP\PDF\Fpdf\Cache\NullCache;
use PXP\PDF\Fpdf\IO\FileIOInterface;
use PXP\PDF\Fpdf\Log\NullLogger;
use PXP\PDF\Fpdf\Object\Parser\PDFParser;
use PXP\PDF\Fpdf\Object\PDFObjectInterface;
use PXP\PDF\Fpdf\Xref\PDFXrefTable;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Registry for managing all PDF objects in a document.
 */
final class PDFObjectRegistry
{
    /**
     * @var array<int, PDFObjectNode>
     */
    private array $objects = [];

    private int $nextObjectNumber = 1;

    /**
     * Lazy loading context - supports both file-based and memory-based.
     */
    private ?string $rawContent = null;
    private ?PDFParser $parser = null;
    private ?PDFXrefTable $xrefTable = null;

    /**
     * File-based lazy loading context.
     */
    private ?string $filePath = null;
    private ?FileIOInterface $fileIO = null;

    /**
     * Create a new registry.
     * Supports both file-based and memory-based lazy loading.
     * File-based takes precedence if both are provided.
     *
     * @param string|null $rawContent Raw PDF content for on-demand parsing (memory-based)
     * @param PDFParser|null $parser Parser instance for on-demand parsing
     * @param PDFXrefTable|null $xrefTable Xref table for object locations
     * @param string|null $filePath PDF file path for file-based lazy loading
     * @param FileIOInterface|null $fileIO File IO interface for reading from file
     * @param CacheItemPoolInterface|null $cache Cache for object lookups
     * @param LoggerInterface|null $logger Logger for debug information
     */
    public function __construct(
        ?string $rawContent = null,
        ?PDFParser $parser = null,
        ?PDFXrefTable $xrefTable = null,
        ?string $filePath = null,
        ?FileIOInterface $fileIO = null,
        ?CacheItemPoolInterface $cache = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->rawContent = $rawContent;
        $this->parser = $parser;
        $this->xrefTable = $xrefTable;
        $this->filePath = $filePath;
        $this->fileIO = $fileIO;
        $this->cache = $cache ?? new NullCache();
        $this->logger = $logger ?? new NullLogger();
    }

    private CacheItemPoolInterface $cache;
    private LoggerInterface $logger;

    /**
     * Check if lazy loading is enabled (file-based or memory-based).
     */
    private function isLazyLoadingEnabled(): bool
    {
        // File-based lazy loading
        if ($this->filePath !== null && $this->fileIO !== null && $this->parser !== null && $this->xrefTable !== null) {
            return true;
        }

        // Memory-based lazy loading
        if ($this->rawContent !== null && $this->parser !== null && $this->xrefTable !== null) {
            return true;
        }

        return false;
    }

    /**
     * Check if file-based lazy loading is enabled.
     */
    private function isFileBasedLazyLoading(): bool
    {
        return $this->filePath !== null && $this->fileIO !== null && $this->parser !== null && $this->xrefTable !== null;
    }

    /**
     * Register an object node.
     */
    public function register(PDFObjectNode $node): void
    {
        $objectNumber = $node->getObjectNumber();
        $this->objects[$objectNumber] = $node;
        if ($objectNumber >= $this->nextObjectNumber) {
            $this->nextObjectNumber = $objectNumber + 1;
        }

        // If the registry grows too large and lazy-loading is enabled (i.e. we can reload
        // objects from the source PDF on-demand), proactively clear already-loaded objects to
        // avoid unbounded memory growth when merging many PDFs. For in-memory-only documents
        // (lazy loading disabled), don't clear because those objects are necessary for final
        // serialization.
        $MAX_LOADED_IN_MEMORY = 2048;
        if ($this->isLazyLoadingEnabled() && count($this->objects) > $MAX_LOADED_IN_MEMORY) {
            $this->logger->debug('Loaded objects cap reached, clearing in-memory registry to avoid OOM (lazy-loading active)', [
                'loaded_objects' => count($this->objects),
                'cap' => $MAX_LOADED_IN_MEMORY,
            ]);
            $this->objects = [];
            // Attempt to free memory immediately
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        // Cache the object if cacheable (avoid serializing stream-heavy objects)
        $cacheKey = $this->getCacheKey($objectNumber);
        if ($this->isCacheableNode($node)) {
            $cacheItem = $this->cache->getItem($cacheKey);
            $cacheItem->set($node);
            $this->cache->save($cacheItem);
        } else {
            $this->logger->debug('Skipping external cache for non-cacheable object', [
                'object_number' => $objectNumber,
                'type' => get_class($node->getValue()),
            ]);
        }

        $this->logger->debug('Object registered', [
            'object_number' => $objectNumber,
            'type' => get_class($node->getValue()),
        ]);
    }

    /**
     * Get an object by object number.
     * If lazy loading is enabled and object is not cached, it will be parsed on-demand.
     */
    public function get(int $objectNumber): ?PDFObjectNode
    {
        // Check memory cache first
        if (isset($this->objects[$objectNumber])) {
            $this->logger->debug('Object retrieved from memory cache', [
                'object_number' => $objectNumber,
                'cache_hit' => true,
            ]);
            return $this->objects[$objectNumber];
        }

        // Check external cache
        $cacheKey = $this->getCacheKey($objectNumber);
        $cacheItem = $this->cache->getItem($cacheKey);
        if ($cacheItem->isHit()) {
            $node = $cacheItem->get();
            if ($node instanceof PDFObjectNode) {
                // Do NOT store in memory - keep only in external cache
                $this->logger->debug('Object retrieved from external cache', [
                    'object_number' => $objectNumber,
                    'cache_hit' => true,
                ]);
                return $node;
            }
        }

        $this->logger->debug('Object cache miss, attempting lazy load', [
            'object_number' => $objectNumber,
            'cache_hit' => false,
            'lazy_loading_enabled' => $this->isLazyLoadingEnabled(),
        ]);

        // If lazy loading is enabled, parse on-demand
        if ($this->isLazyLoadingEnabled()) {
            $xrefEntry = $this->xrefTable->getEntry($objectNumber);

            // If entry not found or entry indicates compressed object, try compressed resolution
            if (($xrefEntry === null || $xrefEntry->isCompressed())) {
                $compressed = $this->xrefTable->getCompressedEntry($objectNumber);
                if ($compressed !== null) {
                    $this->logger->debug('Attempting to resolve compressed object', [
                        'object_number' => $objectNumber,
                        'object_stream' => $compressed['stream'],
                        'index' => $compressed['index'],
                        'file_based' => $this->isFileBasedLazyLoading(),
                    ]);

                    $node = null;
                    if ($this->isFileBasedLazyLoading()) {
                        $node = $this->parser->parseObjectFromObjectStreamInFile(
                            $this->filePath,
                            $this->fileIO,
                            $compressed['stream'],
                            $compressed['index'],
                            $this->xrefTable
                        );
                    } else {
                        // Memory-based not implemented for compressed objects yet
                        $node = null;
                    }

                    if ($node !== null) {
                        // Store in external cache only, NOT in memory
                        if ($this->isCacheableNode($node)) {
                            $cacheItem->set($node);
                            $this->cache->save($cacheItem);
                            $this->logger->debug('Compressed object resolved and cached externally', [
                                'object_number' => $objectNumber,
                                'type' => get_class($node->getValue()),
                            ]);
                        } else {
                            $this->logger->debug('Compressed object resolved but skipped external cache (non-cacheable)', [
                                'object_number' => $objectNumber,
                                'type' => get_class($node->getValue()),
                            ]);
                        }
                        return $node;
                    }
                }
            }

            if ($xrefEntry !== null && !$xrefEntry->isFree()) {
                $node = null;

                $this->logger->debug('Lazy loading object', [
                    'object_number' => $objectNumber,
                    'file_based' => $this->isFileBasedLazyLoading(),
                ]);

                // Use file-based lazy loading if available
                if ($this->isFileBasedLazyLoading()) {
                    $node = $this->parser->parseObjectByNumberFromFile(
                        $this->filePath,
                        $this->fileIO,
                        $objectNumber,
                        $this->xrefTable
                    );
                } else {
                    // Use memory-based lazy loading
                    $node = $this->parser->parseObjectByNumber(
                        $this->rawContent,
                        $objectNumber,
                        $this->xrefTable
                    );
                }

                if ($node !== null) {
                    // Store in external cache only, NOT in memory
                    if ($this->isCacheableNode($node)) {
                        $cacheItem->set($node);
                        $this->cache->save($cacheItem);
                        $this->logger->debug('Object lazy loaded and cached externally', [
                            'object_number' => $objectNumber,
                            'type' => get_class($node->getValue()),
                        ]);
                    } else {
                        $this->logger->debug('Object lazy loaded but skipped external cache (non-cacheable)', [
                            'object_number' => $objectNumber,
                            'type' => get_class($node->getValue()),
                        ]);
                    }
                    return $node;
                }
            }
        }

        $this->logger->debug('Object not found', [
            'object_number' => $objectNumber,
        ]);

        return null;
    }

    /**
     * Get all registered objects.
     * If lazy loading is enabled, all objects will be lazy-loaded from xref table.
     *
     * @return array<int, PDFObjectNode>
     */
    public function getAll(): array
    {
        // If lazy loading is enabled, lazy-load all objects from xref table
        if ($this->isLazyLoadingEnabled()) {
            $xrefEntries = $this->xrefTable->getAllEntries();
            foreach ($xrefEntries as $objectNumber => $xrefEntry) {
                if ($xrefEntry->isFree()) {
                    continue;
                }
                // This will lazy-load if not already cached
                $this->get($objectNumber);
            }
        }

        return $this->objects;
    }

    /**
     * Get only already-loaded objects without forcing lazy loading.
     * This is useful for type-based searches without memory explosion.
     *
     * @return array<int, PDFObjectNode>
     */
    public function getLoadedObjects(): array
    {
        return $this->objects;
    }

    /**
     * Check if an object exists.
     * If lazy loading is enabled, checks xref table as well.
     */
    public function has(int $objectNumber): bool
    {
        // Check cache first
        if (isset($this->objects[$objectNumber])) {
            return true;
        }

        // If lazy loading is enabled, check xref table
        if ($this->isLazyLoadingEnabled()) {
            $xrefEntry = $this->xrefTable->getEntry($objectNumber);
            return $xrefEntry !== null && !$xrefEntry->isFree();
        }

        return false;
    }

    /**
     * Remove an object.
     */
    public function remove(int $objectNumber): void
    {
        unset($this->objects[$objectNumber]);
        $cacheKey = $this->getCacheKey($objectNumber);
        $this->cache->deleteItem($cacheKey);
        $this->logger->debug('Object removed', [
            'object_number' => $objectNumber,
        ]);
    }

    /**
     * Get cache key for an object, including file path hash to make it unique per PDF.
     */
    private function getCacheKey(int $objectNumber): string
    {
        // Include file path hash in cache key to make it unique per PDF file
        // This prevents objects from different PDFs with same object number from colliding
        $fileHash = '';
        if ($this->filePath !== null) {
            // Use absolute path for consistency (relative paths may vary)
            $absolutePath = realpath($this->filePath) ?: $this->filePath;
            $fileHash = '_' . md5($absolutePath);
        } elseif ($this->rawContent !== null) {
            // For memory-based, use hash of content (first 1KB should be enough for uniqueness)
            $fileHash = '_' . md5(substr($this->rawContent, 0, 1024));
        }
        return 'pxp_pdf_object_' . $objectNumber . $fileHash;
    }

    /**
     * Decide if a node is safe to serialize into the external cache.
     * We avoid caching nodes that contain stream objects (e.g., image/XObject/FontFile streams)
     * because serializing large binary buffers can spike memory usage and cause OOMs.
     */
    private function isCacheableNode(PDFObjectNode $node): bool
    {
        $val = $node->getValue();
        return !$this->containsStream($val);
    }

    /**
     * Recursively inspect an object for the presence of any PDFStream instances.
     * We avoid following PDFReference instances to prevent triggering lazy loads.
     * The $visited set prevents infinite recursion.
     */
    private function containsStream(PDFObjectInterface $obj, array &$visited = []): bool
    {
        // Safety: avoid unbounded recursion on very large or circular object graphs.
        // If we've visited a large number of nodes, treat the object as non-cacheable
        // so we err on the side of skipping external cache for complex structures.
        $MAX_VISITED = 2048;
        if (count($visited) > $MAX_VISITED) {
            return true;
        }

        $id = spl_object_id($obj);
        if (in_array($id, $visited, true)) {
            return false;
        }
        $visited[] = $id;

        // If we encounter a PDFReference, treat it as non-cacheable. References may point to
        // stream-containing objects in other parts of the PDF and following them risks
        // pulling large stream content into memory during marshalling.
        if ($obj instanceof \PXP\PDF\Fpdf\Object\Base\PDFReference) {
            return true;
        }

        if ($obj instanceof \PXP\PDF\Fpdf\Stream\PDFStream) {
            return true;
        }

        if ($obj instanceof \PXP\PDF\Fpdf\Object\Base\PDFDictionary) {
            foreach ($obj->getAllEntries() as $v) {
                if ($v instanceof PDFObjectInterface && $this->containsStream($v, $visited)) {
                    return true;
                }
            }
        } elseif ($obj instanceof \PXP\PDF\Fpdf\Object\Base\PDFArray) {
            foreach ($obj->getAll() as $item) {
                if ($item instanceof PDFObjectInterface && $this->containsStream($item, $visited)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Clear all cached objects.
     * Useful for memory management when processing large PDFs.
     * Note: Objects will be re-parsed on-demand if lazy loading is enabled.
     */
    public function clearCache(): void
    {
        $count = count($this->objects);
        $this->objects = [];
        // Note: We can't clear the entire cache as it may contain objects from other PDFs
        // Instead, we only clear memory cache. External cache entries will expire naturally.
        $this->logger->debug('Cache cleared', [
            'objects_cleared' => $count,
        ]);
    }

    /**
     * Get the number of cached objects.
     */
    public function getCacheSize(): int
    {
        return count($this->objects);
    }

    /**
     * Get the next available object number.
     */
    public function getNextObjectNumber(): int
    {
        return $this->nextObjectNumber++;
    }

    /**
     * Rebuild object numbers sequentially starting from 1.
     */
    public function rebuildObjectNumbers(): void
    {
        $objects = array_values($this->objects);
        $this->objects = [];
        $this->nextObjectNumber = 1;

        foreach ($objects as $node) {
            $node->setObjectNumber($this->nextObjectNumber++);
            $this->objects[$node->getObjectNumber()] = $node;
        }
    }

    /**
     * Get the highest object number.
     * If lazy loading is enabled, checks xref table as well.
     */
    public function getMaxObjectNumber(): int
    {
        $maxFromCache = 0;
        if (!empty($this->objects)) {
            $maxFromCache = max(array_keys($this->objects));
        }

        if ($this->isLazyLoadingEnabled()) {
            $xrefEntries = $this->xrefTable->getAllEntries();
            if (!empty($xrefEntries)) {
                $maxFromXref = max(array_keys($xrefEntries));
                return max($maxFromCache, $maxFromXref);
            }
        }

        return $maxFromCache;
    }
}
