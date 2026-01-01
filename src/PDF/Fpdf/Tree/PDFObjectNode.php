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

use PXP\PDF\Fpdf\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Object\PDFObjectInterface;

/**
 * Represents a node in the PDF object tree.
 * Each node corresponds to one PDF object with its number and generation.
 */
final class PDFObjectNode
{
    private int $objectNumber;
    private int $generationNumber;
    private ?int $offset = null;
    private PDFObjectInterface $object;

    /**
     * @var array<int, PDFObjectNode>
     */
    private array $children = [];

    public function __construct(
        int $objectNumber,
        PDFObjectInterface $object,
        int $generationNumber = 0,
        ?int $offset = null,
    ) {
        $this->objectNumber = $objectNumber;
        $this->generationNumber = $generationNumber;
        $this->object = $object;
        $this->offset = $offset;
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
        return $this->object;
    }

    public function setValue(PDFObjectInterface $object): void
    {
        $this->object = $object;
    }

    /**
     * Resolve a reference to an object node.
     * This method should be called with the document's registry.
     */
    public function resolveReference(PDFReference $ref, PDFObjectRegistry $registry): ?PDFObjectNode
    {
        return $registry->get($ref->getObjectNumber());
    }

    /**
     * Get all child objects referenced by this node.
     * This method should be called with the document's registry.
     *
     * @return array<int, PDFObjectNode>
     */
    public function getChildren(PDFObjectRegistry $registry): array
    {
        $children = [];
        $this->collectReferences($this->object, $registry, $children);

        return $children;
    }

    /**
     * Recursively collect all object references.
     *
     * @param array<int, PDFObjectNode> $children
     */
    private function collectReferences(PDFObjectInterface $obj, PDFObjectRegistry $registry, array &$children): void
    {
        if ($obj instanceof PDFReference) {
            $child = $registry->get($obj->getObjectNumber());
            if ($child !== null && !isset($children[$child->getObjectNumber()])) {
                $children[$child->getObjectNumber()] = $child;
                $this->collectReferences($child->getValue(), $registry, $children);
            }
        } elseif ($obj instanceof \PXP\PDF\Fpdf\Object\Base\PDFDictionary) {
            foreach ($obj->getAllEntries() as $value) {
                if ($value instanceof PDFObjectInterface) {
                    $this->collectReferences($value, $registry, $children);
                }
            }
        } elseif ($obj instanceof \PXP\PDF\Fpdf\Object\Base\PDFArray) {
            foreach ($obj->getAll() as $item) {
                if ($item instanceof PDFObjectInterface) {
                    $this->collectReferences($item, $registry, $children);
                }
            }
        }
    }

    /**
     * Serialize this object node to PDF format.
     */
    public function __toString(): string
    {
        $result = sprintf('%d %d obj', $this->objectNumber, $this->generationNumber) . "\n";
        $result .= (string) $this->object . "\n";
        $result .= 'endobj';

        return $result;
    }
}
