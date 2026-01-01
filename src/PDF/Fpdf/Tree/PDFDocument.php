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

use PXP\PDF\Fpdf\Exception\FpdfException;
use PXP\PDF\Fpdf\IO\FileReaderInterface;
use PXP\PDF\Fpdf\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Object\PDFObjectInterface;
use PXP\PDF\Fpdf\Xref\PDFXrefTable;

/**
 * Root document class representing a complete PDF as a tree structure.
 */
final class PDFDocument
{
    private PDFHeader $header;
    private PDFXrefTable $xrefTable;
    private PDFTrailer $trailer;
    private PDFObjectRegistry $objectRegistry;
    private ?PDFObjectNode $rootNode = null;

    public function __construct(string $version = '1.3', ?PDFObjectRegistry $objectRegistry = null)
    {
        $this->header = new PDFHeader($version);
        $this->xrefTable = new PDFXrefTable();
        $this->trailer = new PDFTrailer();
        $this->objectRegistry = $objectRegistry ?? new PDFObjectRegistry();
    }

    public function getHeader(): PDFHeader
    {
        return $this->header;
    }

    public function getXrefTable(): PDFXrefTable
    {
        return $this->xrefTable;
    }

    public function getTrailer(): PDFTrailer
    {
        return $this->trailer;
    }

    public function getObjectRegistry(): PDFObjectRegistry
    {
        return $this->objectRegistry;
    }

    /**
     * Get an object by object number.
     */
    public function getObject(int $objectNumber): ?PDFObjectNode
    {
        return $this->objectRegistry->get($objectNumber);
    }

    /**
     * Add a new object to the document.
     */
    public function addObject(PDFObjectInterface $object, ?int $objectNumber = null): PDFObjectNode
    {
        if ($objectNumber === null) {
            $objectNumber = $this->objectRegistry->getNextObjectNumber();
        }

        $node = new PDFObjectNode($objectNumber, $object);
        $this->objectRegistry->register($node);

        return $node;
    }

    /**
     * Remove an object from the document.
     */
    public function removeObject(int $objectNumber): void
    {
        $this->objectRegistry->remove($objectNumber);
        $this->xrefTable->getEntry($objectNumber)?->setFree(true);
    }

    /**
     * Get the root catalog object.
     */
    public function getRoot(): ?PDFObjectNode
    {
        return $this->rootNode;
    }

    /**
     * Set the root catalog object.
     */
    public function setRoot(PDFObjectNode $rootNode): void
    {
        $this->rootNode = $rootNode;
        $this->trailer->setRoot(new PDFReference($rootNode->getObjectNumber()));
    }

    /**
     * Get the pages object.
     */
    public function getPages(): ?PDFObjectNode
    {
        $root = $this->getRoot();
        if ($root === null) {
            return null;
        }

        $rootDict = $root->getValue();
        if (!$rootDict instanceof \PXP\PDF\Fpdf\Object\Base\PDFDictionary) {
            return null;
        }

        $pagesRef = $rootDict->getEntry('/Pages');
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
        $pages = $this->getPages();
        if ($pages === null) {
            return null;
        }

        $pagesDict = $pages->getValue();
        if (!$pagesDict instanceof \PXP\PDF\Fpdf\Object\Base\PDFDictionary) {
            return null;
        }

        $kids = $pagesDict->getEntry('/Kids');
        if (!$kids instanceof \PXP\PDF\Fpdf\Object\Base\PDFArray) {
            return null;
        }

        $pageIndex = $pageNumber - 1;
        if ($pageIndex < 0 || $pageIndex >= $kids->count()) {
            return null;
        }

        $pageRef = $kids->get($pageIndex);
        if (!$pageRef instanceof PDFReference) {
            return null;
        }

        return $this->getObject($pageRef->getObjectNumber());
    }

    /**
     * Parse PDF from file.
     */
    public static function parseFromFile(string $filePath, ?FileReaderInterface $fileReader = null): self
    {
        if ($fileReader === null) {
            $fileReader = new \PXP\PDF\Fpdf\IO\FileIO();
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
        $document = new self();

        // Parse header
        $header = PDFHeader::parse($content);
        if ($header !== null) {
            $document->header = $header;
        }

        // Basic xref parsing (full parsing will be in PDFParser)
        $xrefPos = strrpos($content, 'xref');
        if ($xrefPos !== false) {
            $xrefEnd = strpos($content, 'trailer', $xrefPos);
            if ($xrefEnd !== false) {
                $xrefContent = substr($content, $xrefPos + 4, $xrefEnd - $xrefPos - 4);
                $document->xrefTable->parseFromString($xrefContent);
            }
        }

        // Parse trailer
        $trailerPos = strrpos($content, 'trailer');
        if ($trailerPos !== false) {
            $trailerSection = substr($content, $trailerPos);
            $rootMatch = [];
            if (preg_match('/\/Root\s+(\d+)\s+(\d+)\s+R/', $trailerSection, $rootMatch)) {
                $rootObjNum = (int) $rootMatch[1];
                // Root will be set when objects are parsed
            }
        }

        return $document;
    }

    /**
     * Serialize the document to PDF format.
     */
    public function serialize(): string
    {
        $parts = [];
        $offsets = [];
        $offset = 0;

        // Header
        $headerStr = (string) $this->header;
        $parts[] = $headerStr;
        $offset += strlen($headerStr);

        // Serialize all objects
        $objects = $this->objectRegistry->getAll();
        ksort($objects);

        foreach ($objects as $objectNumber => $node) {
            $offsets[$objectNumber] = $offset;
            $objStr = (string) $node . "\n";
            $parts[] = $objStr;
            $offset += strlen($objStr);
        }

        // Rebuild xref table
        $this->xrefTable->rebuild($offsets);

        // Xref table
        $xrefOffset = $offset;
        $xrefStr = $this->xrefTable->serialize();
        $parts[] = $xrefStr;
        $offset += strlen($xrefStr);

        // Trailer
        $this->trailer->setSize($this->objectRegistry->getMaxObjectNumber() + 1);
        $trailerStr = $this->trailer->serialize($xrefOffset);
        $parts[] = $trailerStr;

        return implode('', $parts);
    }
}
