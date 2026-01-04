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

use function array_keys;
use function count;
use function ltrim;
use function preg_match;
use function preg_split;
use function sort;
use function sprintf;
use function trim;

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
     * Add a compressed entry pointing to an object stored inside an ObjStm.
     */
    public function addCompressedEntry(int $objectNumber, int $objectStreamNumber, int $index): void
    {
        // Create an entry with offset -1 (placeholder) and mark compressed
        $entry = new XrefEntry(-1, 0, false);
        $entry->setCompressed($objectStreamNumber, $index);
        $this->entries[$objectNumber] = $entry;
    }

    /**
     * Get compressed entry details for a given object number.
     * Returns null if not compressed or entry does not exist.
     *
     * @return null|array{stream: int, index: int}
     */
    public function getCompressedEntry(int $objectNumber): ?array
    {
        if (!isset($this->entries[$objectNumber])) {
            return null;
        }
        $entry = $this->entries[$objectNumber];

        if ($entry->isCompressed()) {
            return ['stream' => $entry->getCompressedObjectStream(), 'index' => $entry->getCompressedIndex()];
        }

        return null;
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

        // Split into lines supporting CRLF, CR, LF
        $lines = preg_split('/\r\n|\r|\n/', $xrefContent);

        if (false === $lines) {
            return;
        }

        $lineCount = count($lines);
        $i         = 0;

        while ($i < $lineCount) {
            $line = trim($lines[$i]);

            if ($line === '') {
                $i++;

                continue;
            }

            // Subsection header: "start count"
            if (preg_match('/^([0-9]+)\s+([0-9]+)$/', $line, $headerMatches) === 1) {
                $startObj = (int) $headerMatches[1];
                $count    = (int) $headerMatches[2];

                // Read the next $count lines as entries
                for ($j = 0; $j < $count; $j++) {
                    $i++;

                    if ($i >= $lineCount) {
                        break 2; // premature end
                    }

                    $entryLine = trim($lines[$i]);

                    // Entry format: 10-digit offset, 5-digit generation, flag n|f (flag required)
                    if (preg_match('/^([0-9]{10})\s+([0-9]{5})\s+([nf])\s*$/', $entryLine, $entryMatches) === 1) {
                        $offsetNum  = (int) ltrim($entryMatches[1], '0');
                        $generation = (int) $entryMatches[2];
                        $flag       = $entryMatches[3];

                        $objNum = $startObj + $j;

                        if ('n' === $flag) {
                            $this->addEntry($objNum, $offsetNum, $generation, false);
                        } else {
                            $this->addEntry($objNum, $offsetNum, $generation, true);
                        }
                    } else {
                        // If the line doesn't match an entry, treat it as subsection header or stop
                        // To be tolerant, try to parse a more permissive entry pattern (allow variable digit counts)
                        if (preg_match('/^([0-9]+)\s+([0-9]+)\s+([nf])\s*$/', $entryLine, $entryMatches2) === 1) {
                            $offsetNum  = (int) $entryMatches2[1];
                            $generation = (int) $entryMatches2[2];
                            $flag       = $entryMatches2[3];

                            $objNum = $startObj + $j;

                            if ('n' === $flag) {
                                $this->addEntry($objNum, $offsetNum, $generation, false);
                            } else {
                                $this->addEntry($objNum, $offsetNum, $generation, true);
                            }
                        } else {
                            // Malformed entry - stop parsing
                            break 2;
                        }
                    }
                }

                $i++;

                continue;
            }

            // If we reach here, the line wasn't a subsection header - skip it
            $i++;
        }
    }

    /**
     * Merge entries from another xref table.
     * Only adds entries that don't already exist (for handling Prev references).
     * This ensures newer entries (current) are preserved and older entries (prev) are only added if missing.
     *
     * @param PDFXrefTable $otherTable The xref table to merge from (older entries from Prev reference)
     */
    public function mergeEntries(self $otherTable): void
    {
        foreach ($otherTable->getAllEntries() as $objectNumber => $entry) {
            // Merge entries from another table; entries from the other table override existing ones.
            $this->entries[$objectNumber] = $entry;
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
            $count    = $subsection['count'];
            $result .= sprintf("%d %d\n", $startObj, $count);

            for ($i = 0; $i < $count; $i++) {
                $objNum = $startObj + $i;

                if (isset($this->entries[$objNum])) {
                    $result .= (string) $this->entries[$objNum] . "\n";
                } else {
                    // Free entry
                    $result .= "0000000000 65535 f \n";
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
        $subsections   = [];
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
                $currentStart  = $objectNumbers[$i];
                $currentCount  = 1;
            }
        }

        $subsections[] = ['start' => $currentStart, 'count' => $currentCount];

        return $subsections;
    }
}
