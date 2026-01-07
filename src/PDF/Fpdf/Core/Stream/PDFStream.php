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
namespace PXP\PDF\Fpdf\Core\Stream;

use function array_filter;
use function array_values;
use function count;
use function in_array;
use function ltrim;
use function strlen;
use PXP\PDF\Fpdf\Core\Object\Base\PDFArray;
use PXP\PDF\Fpdf\Core\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Core\Object\Base\PDFName;
use PXP\PDF\Fpdf\Core\Object\Base\PDFNumber;
use PXP\PDF\Fpdf\Core\Object\Base\PDFObject;

/**
 * Enhanced PDF stream object with encoding/decoding support.
 */
final class PDFStream extends PDFObject
{
    private ?string $encodedData = null;

    /**
     * @var array<string>
     */
    private array $filters = [];

    public function __construct(
        private readonly PDFDictionary $pdfDictionary,
        private string $data,
        private bool $dataIsEncoded = false,
    ) {
        $this->updateFiltersFromDictionary();
    }

    public function __toString(): string
    {
        $this->updateDictionary();
        $encodedData = $this->getEncodedData();

        $result = $this->pdfDictionary . "\n";
        $result .= "stream\n";
        $result .= $encodedData . "\n";
        $result .= 'endstream';

        // CRITICAL FIX: Do NOT clear $this->encodedData because it needs to remain
        // available for subsequent getEncodedData() calls during object copying.
        // Example: PDFSplitter.copyObjectWithReferences() needs encoded data after
        // stream serialization.
        // $this->encodedData = null;  // REMOVED - causes 37% content loss bug!

        // Clear raw data when it was encoded to free the memory used by large streams.
        // This is safe because we keep $this->encodedData for subsequent getEncodedData() calls.
        if ($this->dataIsEncoded) {
            $this->data = '';
        }

        return $result;
    }

    /**
     * Get the stream dictionary.
     */
    public function getDictionary(): PDFDictionary
    {
        return $this->pdfDictionary;
    }

    /**
     * Get decoded stream data.
     */
    public function getDecodedData(): string
    {
        if ($this->dataIsEncoded) {
            $streamDecoder = new StreamDecoder;

            return $streamDecoder->decode($this->data, $this->pdfDictionary);
        }

        return $this->data;
    }

    /**
     * Set decoded stream data.
     */
    public function setData(string $data): void
    {
        $this->data          = $data;
        $this->dataIsEncoded = false;
        $this->encodedData   = null;
    }

    /**
     * Set already encoded stream data directly. Useful when copying streams without decoding/re-encoding.
     */
    public function setEncodedData(string $encoded): void
    {
        $this->encodedData   = $encoded;
        $this->dataIsEncoded = true;
        // Keep $this->data as-is (raw encoded data) but ensure getEncodedData returns the provided value
    }

    /**
     * Get encoded stream data (with filters applied).
     */
    public function getEncodedData(): string
    {
        if ($this->encodedData !== null) {
            return $this->encodedData;
        }

        // If the stream data is already encoded (parsed from file or set via setEncodedData),
        // return the raw data directly without re-encoding to avoid unnecessary memory usage.
        if ($this->dataIsEncoded) {
            $this->encodedData = $this->data;

            return $this->encodedData;
        }

        if ($this->filters === []) {
            $this->encodedData = $this->data;
        } else {
            $streamEncoder     = new StreamEncoder;
            $this->encodedData = $streamEncoder->encode($this->data, $this->filters);
        }

        return $this->encodedData;
    }

    /**
     * Add a filter to the stream.
     */
    public function addFilter(string $filterName, ?PDFDictionary $pdfDictionary = null): void
    {
        $filterName = ltrim($filterName, '/');

        if (!in_array($filterName, $this->filters, true)) {
            $this->filters[] = $filterName;
        }

        $this->updateDictionary();
        $this->encodedData = null; // Invalidate cache
    }

    /**
     * Remove a filter from the stream.
     */
    public function removeFilter(string $filterName): void
    {
        $filterName    = ltrim($filterName, '/');
        $this->filters = array_values(array_filter($this->filters, static fn (string $f): bool => $f !== $filterName));
        $this->updateDictionary();
        $this->encodedData = null; // Invalidate cache
    }

    /**
     * Check if a filter is active.
     */
    public function hasFilter(string $filterName): bool
    {
        $filterName = ltrim($filterName, '/');

        return in_array($filterName, $this->filters, true);
    }

    /**
     * Get all active filters.
     *
     * @return array<string>
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * Update filters from dictionary.
     */
    private function updateFiltersFromDictionary(): void
    {
        $filter        = $this->pdfDictionary->getEntry('/Filter');
        $this->filters = [];

        if ($filter instanceof PDFName) {
            $this->filters[] = ltrim($filter->getName(), '/');
        } elseif ($filter instanceof PDFArray) {
            foreach ($filter->getAll() as $filterItem) {
                if ($filterItem instanceof PDFName) {
                    $this->filters[] = ltrim($filterItem->getName(), '/');
                }
            }
        }
    }

    /**
     * Update dictionary with current filters and length.
     */
    private function updateDictionary(): void
    {
        // Update /Filter entry
        if ($this->filters === []) {
            $this->pdfDictionary->removeEntry('/Filter');
        } elseif (count($this->filters) === 1) {
            $this->pdfDictionary->addEntry('/Filter', new PDFName($this->filters[0]));
        } else {
            $pdfArray = new PDFArray;

            foreach ($this->filters as $filter) {
                $pdfArray->add(new PDFName($filter));
            }
            $this->pdfDictionary->addEntry('/Filter', $pdfArray);
        }

        // Update /Length entry without forcing an encode when possible to avoid unnecessary memory allocations.
        if ($this->encodedData !== null) {
            $length = strlen($this->encodedData);
        } elseif ($this->dataIsEncoded) {
            // Raw data is already encoded; use it directly without copying.
            $length = strlen($this->data);
        } elseif ($this->filters === []) {
            // No filters applied, so data is stored as-is.
            $length = strlen($this->data);
        } else {
            // Filters present and data not encoded: encode to determine length.
            $encodedData = $this->getEncodedData();
            $length      = strlen($encodedData);
        }

        $this->pdfDictionary->addEntry('/Length', new PDFNumber($length));
    }
}
