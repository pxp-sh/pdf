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

use function sprintf;
use PXP\PDF\Fpdf\Core\Object\Base\PDFArray;
use PXP\PDF\Fpdf\Core\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Core\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Core\Object\PDFObjectInterface;
use Stringable;

/**
 * Represents a node in the PDF object tree.
 * Each node corresponds to one PDF object with its number and generation.
 */
final class PDFObjectNode implements Stringable
{
    public function __construct(private int $objectNumber, private PDFObjectInterface $pdfObject, private int $generationNumber = 0, private ?int $offset = null)
    {
    }

    /**
     * Serialize this object node to PDF format.
     */
    public function __toString(): string
    {
        $result = sprintf('%d %d obj', $this->objectNumber, $this->generationNumber) . "\n";
        $result .= $this->pdfObject . "\n";

        return $result . 'endobj';
    }

    public function getObjectNumber(): int
    {
        return $this->objectNumber;
    }

    public function setObjectNumber(int $objectNumber): void
    {
        $this->objectNumber = $objectNumber;
    }

    public function getGenerationNumber(): int
    {
        return $this->generationNumber;
    }

    public function setGenerationNumber(int $generationNumber): void
    {
        $this->generationNumber = $generationNumber;
    }

    public function getOffset(): ?int
    {
        return $this->offset;
    }

    public function setOffset(?int $offset): void
    {
        $this->offset = $offset;
    }

    public function getValue(): PDFObjectInterface
    {
        return $this->pdfObject;
    }

    public function setValue(PDFObjectInterface $pdfObject): void
    {
        $this->pdfObject = $pdfObject;
    }

    /**
     * Resolve a reference to an object node.
     * This method should be called with the document's registry.
     */
    public function resolveReference(PDFReference $pdfReference, PDFObjectRegistry $pdfObjectRegistry): ?self
    {
        return $pdfObjectRegistry->get($pdfReference->getObjectNumber());
    }

    /**
     * Get all child objects referenced by this node.
     * This method should be called with the document's registry.
     *
     * @return array<int, PDFObjectNode>
     */
    public function getChildren(PDFObjectRegistry $pdfObjectRegistry): array
    {
        $children = [];
        $this->collectReferences($this->pdfObject, $pdfObjectRegistry, $children);

        return $children;
    }

    /**
     * Recursively collect all object references.
     *
     * @param array<int, PDFObjectNode> $children
     */
    private function collectReferences(PDFObjectInterface $pdfObject, PDFObjectRegistry $pdfObjectRegistry, array &$children): void
    {
        if ($pdfObject instanceof PDFReference) {
            $child = $pdfObjectRegistry->get($pdfObject->getObjectNumber());

            if ($child instanceof self && !isset($children[$child->getObjectNumber()])) {
                $children[$child->getObjectNumber()] = $child;
                $this->collectReferences($child->getValue(), $pdfObjectRegistry, $children);
            }
        } elseif ($pdfObject instanceof PDFDictionary) {
            foreach ($pdfObject->getAllEntries() as $allEntry) {
                if ($allEntry instanceof PDFObjectInterface) {
                    $this->collectReferences($allEntry, $pdfObjectRegistry, $children);
                }
            }
        } elseif ($pdfObject instanceof PDFArray) {
            foreach ($pdfObject->getAll() as $item) {
                if ($item instanceof PDFObjectInterface) {
                    $this->collectReferences($item, $pdfObjectRegistry, $children);
                }
            }
        }
    }
}
