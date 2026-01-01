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
     * Parse xref table from PDF content.
     */
    public function parseFromString(string $xrefContent): void
    {
        $this->entries = [];
        $lines = preg_split('/\r?\n/', trim($xrefContent));

        $i = 0;
        while ($i < count($lines)) {
            $line = trim($lines[$i]);
            if (preg_match('/^(\d+)\s+(\d+)$/', $line, $matches)) {
                $startObj = (int) $matches[1];
                $count = (int) $matches[2];
                $i++;

                for ($j = 0; $j < $count && $i < count($lines); $j++, $i++) {
                    $offsetLine = trim($lines[$i]);
                    if (preg_match('/^(\d{10})\s+(\d{5})\s+([nf])$/', $offsetLine, $offsetMatches)) {
                        $offset = (int) $offsetMatches[1];
                        $gen = (int) $offsetMatches[2];
                        $flag = $offsetMatches[3];

                        $this->addEntry($startObj + $j, $offset, $gen, $flag === 'f');
                    }
                }
            } else {
                $i++;
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
