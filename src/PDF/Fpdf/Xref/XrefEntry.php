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

namespace PXP\PDF\Fpdf\Xref;

/**
 * Represents a single entry in the cross-reference table.
 */
final class XrefEntry
{
    private ?int $compressedObjectStream = null;
    private ?int $compressedIndex = null;

    public function __construct(
        private int $offset,
        private int $generation = 0,
        private bool $free = false,
    ) {
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function setOffset(int $offset): void
    {
        $this->offset = $offset;
    }

    public function getGeneration(): int
    {
        return $this->generation;
    }

    public function setGeneration(int $generation): void
    {
        $this->generation = $generation;
    }

    public function isFree(): bool
    {
        return $this->free;
    }

    public function setFree(bool $free): void
    {
        $this->free = $free;
    }

    /**
     * Mark this entry as a compressed object stored inside an ObjStm
     */
    public function setCompressed(int $objectStreamNumber, int $index): void
    {
        $this->compressedObjectStream = $objectStreamNumber;
        $this->compressedIndex = $index;
    }

    public function isCompressed(): bool
    {
        return $this->compressedObjectStream !== null;
    }

    public function getCompressedObjectStream(): ?int
    {
        return $this->compressedObjectStream;
    }

    public function getCompressedIndex(): ?int
    {
        return $this->compressedIndex;
    }

    /**
     * Serialize to PDF xref entry format.
     */
    public function __toString(): string
    {
        if ($this->free) {
            return sprintf('%010d %05d f ', $this->offset, $this->generation);
        }

        return sprintf('%010d %05d n ', $this->offset, $this->generation);
    }
}
