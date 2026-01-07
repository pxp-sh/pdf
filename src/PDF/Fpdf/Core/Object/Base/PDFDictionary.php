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

use function implode;
use function is_float;
use function is_int;
use function is_string;
use function ltrim;
use PXP\PDF\Fpdf\Core\Object\PDFObjectInterface;

/**
 * Represents a PDF dictionary.
 */
class PDFDictionary extends PDFObject
{
    /**
     * @var array<string, float|int|PDFObjectInterface|string>
     */
    protected array $entries = [];

    /**
     * @param null|array<string, float|int|PDFObjectInterface|string> $entries
     */
    public function __construct(?array $entries = null)
    {
        if ($entries !== null) {
            foreach ($entries as $key => $value) {
                $this->addEntry($key, $value);
            }
        }
    }

    public function __toString(): string
    {
        $parts = [];

        foreach ($this->entries as $key => $value) {
            $keyObj = new PDFName($key);

            $parts[] = $keyObj . ' ' . $value;
        }

        return '<<' . "\n" . implode("\n", $parts) . "\n" . '>>';
    }

    /**
     * Add an entry to the dictionary.
     *
     * @param float|int|PDFObjectInterface|string $value
     */
    public function addEntry(PDFName|string $key, mixed $value): self
    {
        $keyName                 = $key instanceof PDFName ? $key->getName() : ltrim($key, '/');
        $this->entries[$keyName] = $this->normalizeValue($value);

        return $this;
    }

    /**
     * Get an entry by key.
     *
     * @return null|float|int|PDFObjectInterface|string
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
     * @return array<string, float|int|PDFObjectInterface|string>
     */
    public function getAllEntries(): array
    {
        return $this->entries;
    }

    /**
     * Normalize a value to a PDF object.
     *
     * @param float|int|PDFObjectInterface|string $value
     */
    protected function normalizeValue(mixed $value): float|int|PDFObjectInterface|string
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
}
