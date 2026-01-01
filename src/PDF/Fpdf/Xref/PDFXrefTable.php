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
 * Manages the PDF cross-reference table.
 */
final class PDFXrefTable
{
    /**
     * @var array<int, XrefEntry>
     */
    private array $entries = [];

    /**
     * Add or update an xref entry.
     */
    public function addEntry(int $objectNumber, int $offset, int $generation = 0, bool $free = false): void
    {
        $this->entries[$objectNumber] = new XrefEntry($offset, $generation, $free);
    }

    /**
     * Get an xref entry.
     */
    public function getEntry(int $objectNumber): ?XrefEntry
    {
        return $this->entries[$objectNumber] ?? null;
    }

    /**
     * Update the offset for an existing entry.
     */
    public function updateOffset(int $objectNumber, int $offset): void
    {
        if (isset($this->entries[$objectNumber])) {
            $this->entries[$objectNumber]->setOffset($offset);
        }
    }

    /**
     * Check if an entry exists.
     */
    public function hasEntry(int $objectNumber): bool
    {
        return isset($this->entries[$objectNumber]);
    }

    /**
     * Get all entries.
     *
     * @return array<int, XrefEntry>
     */
    public function getAllEntries(): array
    {
        return $this->entries;
    }

    /**
     * Rebuild the xref table from object offsets.
     *
     * @param array<int, int> $objectOffsets Object number => offset
     */
    public function rebuild(array $objectOffsets): void
    {
        $this->entries = [];
        foreach ($objectOffsets as $objectNumber => $offset) {
            $this->addEntry($objectNumber, $offset);
        }
    }

    /**
     * Parse xref table from PDF content using regex-based parsing.
     * Supports various whitespace formats and handles subsections properly.
     */
    public function parseFromString(string $xrefContent): void
    {
        $this->entries = [];

        // Skip initial whitespace
        $offset = strspn($xrefContent, " \t\r\n\f\0");
        $objNum = 0;

        // PDF whitespace characters: space, tab, CR, LF, FF, null
        $whitespaceChars = " \t\r\n\f\0";

        // Search for cross-reference entries or subsection headers
        // Pattern: ([0-9]+)[\x20]([0-9]+)[\x20]?([nf]?)(\r\n|[\x20]?[\r\n])
        while (preg_match(
            '/([0-9]+)[\x20]([0-9]+)[\x20]?([nf]?)(\r\n|[\x20]?[\r\n])/',
            $xrefContent,
            $matches,
            \PREG_OFFSET_CAPTURE,
            $offset
        ) > 0) {
            if ($matches[0][1] != $offset) {
                // We are on another section (trailer or end)
                break;
            }

            $offset += \strlen($matches[0][0]);

            $firstNum = (int) $matches[1][0];
            $secondNum = (int) $matches[2][0];
            $flag = $matches[3][0] ?? '';

            if ('n' === $flag) {
                // In-use entry: offset generation n
                // $firstNum is the offset, $secondNum is the generation
                if (!isset($this->entries[$objNum])) {
                    $this->addEntry($objNum, $firstNum, $secondNum, false);
                }
                ++$objNum;
            } elseif ('f' === $flag) {
                // Free entry: next_free_object generation f
                // $firstNum is the next free object, $secondNum is the generation
                if (!isset($this->entries[$objNum])) {
                    $this->addEntry($objNum, $firstNum, $secondNum, true);
                }
                ++$objNum;
            } else {
                // Subsection header: start_object number_of_entries
                // $firstNum is the starting object number, $secondNum is the count
                $objNum = $firstNum;
                // The next entries will be for objects starting at $objNum
            }
        }
    }

    /**
     * Merge entries from another xref table.
     * Only adds entries that don't already exist (for handling Prev references).
     * This ensures newer entries (current) are preserved and older entries (prev) are only added if missing.
     *
     * @param PDFXrefTable $otherTable The xref table to merge from (older entries from Prev reference)
     */
    public function mergeEntries(PDFXrefTable $otherTable): void
    {
        foreach ($otherTable->getAllEntries() as $objectNumber => $entry) {
            // Only add entries that don't already exist (newer entries override older ones)
            if (!isset($this->entries[$objectNumber])) {
                $this->entries[$objectNumber] = $entry;
            }
        }
    }

    /**
     * Serialize xref table to PDF format.
     */
    public function serialize(): string
    {
        if (empty($this->entries)) {
            return "xref\n0 0\n";
        }

        // Group entries into subsections
        $subsections = $this->groupIntoSubsections();

        $result = "xref\n";
        foreach ($subsections as $subsection) {
            $startObj = $subsection['start'];
            $count = $subsection['count'];
            $result .= sprintf("%d %d\n", $startObj, $count);

            for ($i = 0; $i < $count; $i++) {
                $objNum = $startObj + $i;
                if (isset($this->entries[$objNum])) {
                    $result .= (string) $this->entries[$objNum] . "\n";
                } else {
                    // Free entry
                    $result .= sprintf("0000000000 65535 f \n");
                }
            }
        }

        return $result;
    }

    /**
     * Group entries into contiguous subsections.
     *
     * @return array<int, array{start: int, count: int}>
     */
    private function groupIntoSubsections(): array
    {
        $subsections = [];
        $objectNumbers = array_keys($this->entries);
        sort($objectNumbers);

        if (empty($objectNumbers)) {
            return [];
        }

        $currentStart = $objectNumbers[0];
        $currentCount = 1;

        for ($i = 1; $i < count($objectNumbers); $i++) {
            if ($objectNumbers[$i] === $objectNumbers[$i - 1] + 1) {
                $currentCount++;
            } else {
                $subsections[] = ['start' => $currentStart, 'count' => $currentCount];
                $currentStart = $objectNumbers[$i];
                $currentCount = 1;
            }
        }

        $subsections[] = ['start' => $currentStart, 'count' => $currentCount];

        return $subsections;
    }
}
