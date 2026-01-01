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

namespace PXP\PDF\Fpdf\Object\Base;

use PXP\PDF\Fpdf\Object\PDFObjectInterface;

/**
 * Represents a PDF dictionary.
 */
class PDFDictionary extends PDFObject
{
    /**
     * @var array<string, PDFObjectInterface|string|int|float>
     */
    protected array $entries = [];

    /**
     * @param array<string, PDFObjectInterface|string|int|float>|null $entries
     */
    public function __construct(?array $entries = null)
    {
        if ($entries !== null) {
            foreach ($entries as $key => $value) {
                $this->addEntry($key, $value);
            }
        }
    }

    /**
     * Add an entry to the dictionary.
     *
     * @param PDFName|string $key
     * @param PDFObjectInterface|string|int|float $value
     */
    public function addEntry(PDFName|string $key, mixed $value): self
    {
        $keyName = $key instanceof PDFName ? $key->getName() : ltrim($key, '/');
        $this->entries[$keyName] = $this->normalizeValue($value);

        return $this;
    }

    /**
     * Get an entry by key.
     *
     * @return PDFObjectInterface|string|int|float|null
     */
    public function getEntry(string $key): mixed
    {
        $keyName = ltrim($key, '/');

        return $this->entries[$keyName] ?? null;
    }

    /**
     * Check if an entry exists.
     */
    public function hasEntry(string $key): bool
    {
        $keyName = ltrim($key, '/');

        return isset($this->entries[$keyName]);
    }

    /**
     * Remove an entry.
     */
    public function removeEntry(string $key): self
    {
        $keyName = ltrim($key, '/');
        unset($this->entries[$keyName]);

        return $this;
    }

    /**
     * Get all entries.
     *
     * @return array<string, PDFObjectInterface|string|int|float>
     */
    public function getAllEntries(): array
    {
        return $this->entries;
    }

    /**
     * Normalize a value to a PDF object.
     *
     * @param PDFObjectInterface|string|int|float $value
     */
    protected function normalizeValue(mixed $value): PDFObjectInterface|string|int|float
    {
        if ($value instanceof PDFObjectInterface) {
            return $value;
        }

        if (is_string($value)) {
            return new PDFString($value);
        }

        if (is_int($value) || is_float($value)) {
            return new PDFNumber($value);
        }

        return $value;
    }

    public function __toString(): string
    {
        $parts = [];
        foreach ($this->entries as $key => $value) {
            $keyObj = new PDFName($key);
            if ($value instanceof PDFObjectInterface) {
                $parts[] = (string) $keyObj . ' ' . (string) $value;
            } else {
                $parts[] = (string) $keyObj . ' ' . (string) $value;
            }
        }

        return '<<' . "\n" . implode("\n", $parts) . "\n" . '>>';
    }
}
