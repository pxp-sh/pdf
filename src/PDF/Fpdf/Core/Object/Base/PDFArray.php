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
namespace PXP\PDF\Fpdf\Core\Object\Base;

use function count;
use function implode;
use function is_float;
use function is_int;
use function is_string;
use PXP\PDF\Fpdf\Core\Object\PDFObjectInterface;

/**
 * Represents a PDF array.
 */
class PDFArray extends PDFObject
{
    /**
     * @var array<int, float|int|PDFObjectInterface|string>
     */
    protected array $items = [];

    /**
     * @param null|array<int, float|int|PDFObjectInterface|string> $items
     */
    public function __construct(?array $items = null)
    {
        if ($items !== null) {
            foreach ($items as $item) {
                $this->add($item);
            }
        }
    }

    public function __toString(): string
    {
        $parts = [];

        foreach ($this->items as $item) {
            $parts[] = (string) $item;
        }

        return '[' . implode(' ', $parts) . ']';
    }

    /**
     * Add an item to the array.
     *
     * @param float|int|PDFObjectInterface|string $item
     */
    public function add(mixed $item): self
    {
        $this->items[] = $this->normalizeItem($item);

        return $this;
    }

    /**
     * Get an item by index.
     *
     * @return null|float|int|PDFObjectInterface|string
     */
    public function get(int $index): mixed
    {
        return $this->items[$index] ?? null;
    }

    /**
     * Set an item at a specific index.
     *
     * @param float|int|PDFObjectInterface|string $item
     */
    public function set(int $index, mixed $item): self
    {
        $this->items[$index] = $this->normalizeItem($item);

        return $this;
    }

    /**
     * Get all items.
     *
     * @return array<int, float|int|PDFObjectInterface|string>
     */
    public function getAll(): array
    {
        return $this->items;
    }

    /**
     * Get the count of items.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Normalize an item to a PDF object.
     *
     * @param float|int|PDFObjectInterface|string $item
     */
    protected function normalizeItem(mixed $item): float|int|PDFObjectInterface|string
    {
        if ($item instanceof PDFObjectInterface) {
            return $item;
        }

        if (is_string($item)) {
            return new PDFString($item);
        }

        if (is_int($item) || is_float($item)) {
            return new PDFNumber($item);
        }

        return $item;
    }
}
