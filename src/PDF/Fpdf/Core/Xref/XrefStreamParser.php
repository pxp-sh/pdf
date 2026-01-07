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
namespace PXP\PDF\Fpdf\Core\Xref;

use function abs;
use function array_chunk;
use function array_fill;
use function array_sum;
use function array_values;
use function count;
use function gettype;
use function implode;
use function is_array;
use function is_numeric;
use function json_encode;
use function ltrim;
use function min;
use function sprintf;
use function str_contains;
use function strpos;
use function substr;
use function unpack;
use function var_export;
use PXP\PDF\Fpdf\Exceptions\Exception\FpdfException;

/**
 * Parser for cross-reference streams (PDF 1.5+).
 *
 * Cross-reference streams are a more compact format for storing xref information
 * in PDF 1.5 and later. This parser extracts xref entries from stream data.
 */
final class XrefStreamParser
{
    /**
     * Parse xref stream and populate xref table.
     *
     * This is a placeholder implementation. Full implementation requires:
     * - Parsing the stream object structure
     * - Extracting and decoding stream dictionary
     * - Handling stream filters (FlateDecode, etc.)
     * - PNG predictor decoding if present
     * - Extracting entries from decoded stream data
     *
     * @param string            $streamData   Decoded stream data
     * @param array<int, mixed> $streamDict   Stream dictionary entries
     * @param PDFXrefTable      $pdfXrefTable Xref table to populate
     *
     * @throws FpdfException
     *
     * @return null|int Previous xref offset if found in stream dictionary
     */
    public function parseStream(
        string $streamData,
        array $streamDict,
        PDFXrefTable $pdfXrefTable
    ): ?int {
        // Extract required dictionary fields
        $type = $this->getDictValue($streamDict, '/Type');

        if ($type !== '/XRef') {
            throw new FpdfException('Invalid xref stream: Type must be /XRef');
        }

        $w = $this->getDictValue($streamDict, '/W');

        if (!is_array($w) || count($w) !== 3) {
            // Collect available keys for debugging
            $dictKeys      = [];
            $dictStructure = [];
            $counter       = count($streamDict);

            for ($i = 0; $i < $counter; $i += 2) {
                if (isset($streamDict[$i]) && is_array($streamDict[$i]) && ($streamDict[$i][0] ?? '') === '/') {
                    $keyName         = $streamDict[$i][1] ?? 'unknown';
                    $dictKeys[]      = $keyName;
                    $dictStructure[] = sprintf('idx %d: key=%s, value_type=%s', $i, $keyName, gettype($streamDict[$i + 1] ?? 'missing'));
                }
            }
            $wType  = gettype($w);
            $wCount = is_array($w) ? count($w) : 'N/A';
            $wValue = is_array($w) ? json_encode($w) : var_export($w, true);

            throw new FpdfException(
                sprintf(
                    'Invalid xref stream: /W must be array of 3 integers, got %s (count: %s, value: %s). Available keys: %s. Dict structure: %s',
                    $wType,
                    $wCount,
                    $wValue,
                    implode(', ', $dictKeys),
                    implode('; ', $dictStructure),
                ),
            );
        }

        $wb = [
            (int) ($w[0] ?? 0),
            (int) ($w[1] ?? 0),
            (int) ($w[2] ?? 0),
        ];

        // Get Index array (subsection ranges)
        $index       = $this->getDictValue($streamDict, '/Index');
        $indexBlocks = [];

        if (is_array($index) && count($index) > 0) {
            // Index is an array of pairs: [start1, count1, start2, count2, ...]
            for ($i = 0; $i < count($index) - 1; $i += 2) {
                if (isset($index[$i], $index[$i + 1])) {
                    $indexBlocks[] = [(int) $index[$i], (int) $index[$i + 1]];
                }
            }
        }

        // Get DecodeParms for PNG predictor
        $decodeParms = $this->getDictValue($streamDict, '/DecodeParms');
        $columns     = 0;
        $predictor   = null;

        if (is_array($decodeParms)) {
            $columns   = (int) ($this->getDictValue($decodeParms, '/Columns') ?? 0);
            $predictor = $this->getDictValue($decodeParms, '/Predictor');

            if ($predictor !== null) {
                $predictor = (int) $predictor;
            }
        }

        // Get Prev offset
        $prevOffset = $this->getDictValue($streamDict, '/Prev');
        $prev       = $prevOffset !== null ? (int) $prevOffset : null;

        // Decode stream data
        $decodedData = $this->decodeStreamData($streamData, $wb, $columns, $predictor);

        // Extract xref entries
        $objNum            = $indexBlocks[0][0] ?? 0;
        $currentBlockIndex = 0;
        $remainingInBlock  = $indexBlocks[0][1] ?? count($decodedData);

        foreach ($decodedData as $row) {
            $type   = $row[0] ?? 1; // Default to type 1 if w[0] is 0
            $field1 = $row[1] ?? 0;
            $field2 = $row[2] ?? 0;

            switch ($type) {
                case 0: // Free entry
                    // Skip free entries for now
                    break;

                case 1: // In-use, uncompressed
                    $allEntries = $pdfXrefTable->getAllEntries();

                    if (!isset($allEntries[$objNum])) {
                        $pdfXrefTable->addEntry($objNum, $field1, $field2, false);
                    }

                    break;

                case 2: // Compressed object
                    // Record compressed object mapping so registry/parser can resolve it lazily
                    $pdfXrefTable->addCompressedEntry($objNum, $field1, $field2);

                    break;
            }

            $objNum++;
            $remainingInBlock--;

            if ($remainingInBlock <= 0 && $currentBlockIndex + 1 < count($indexBlocks)) {
                $currentBlockIndex++;
                $objNum           = $indexBlocks[$currentBlockIndex][0];
                $remainingInBlock = $indexBlocks[$currentBlockIndex][1];
            }
        }

        return $prev;
    }

    /**
     * Decode stream data, handling PNG predictor if present.
     *
     * @param string                        $streamData Raw stream data
     * @param array{0: int, 1: int, 2: int} $wb         Field widths
     * @param int                           $columns    Number of columns for PNG predictor
     * @param null|int                      $predictor  PNG predictor value
     *
     * @return array<array{0: int, 1: int, 2: int}> Decoded rows
     */
    private function decodeStreamData(
        string $streamData,
        array $wb,
        int $columns,
        ?int $predictor
    ): array {
        $rowlen = array_sum($wb);

        if ($rowlen <= 0) {
            return [];
        }

        // Convert stream to array of bytes (unpack returns 1-indexed array)
        $sdata = unpack('C*', $streamData);

        if ($sdata === false || $sdata === []) {
            return [];
        }

        // Convert to 0-indexed array for easier handling
        $bytes = array_values($sdata);

        // Split into rows
        $ddata = array_chunk($bytes, $rowlen);

        // Apply PNG predictor if present
        if ($predictor !== null && $columns > 0) {
            $ddata = $this->applyPngPredictor($ddata, $columns);
        }

        // Extract fields from rows
        $result = [];

        foreach ($ddata as $ddatum) {
            $fields = [0, 0, 0];

            if ($wb[0] === 0) {
                $fields[0] = 1; // Default type
            }

            $i = 0;

            for ($c = 0; $c < 3; $c++) {
                for ($b = 0; $b < $wb[$c]; $b++) {
                    if (isset($ddatum[$i])) {
                        // Build multi-byte value: most significant byte first
                        $fields[$c] = ($fields[$c] << 8) | $ddatum[$i];
                    }
                    $i++;
                }
            }
            $result[] = $fields;
        }

        return $result;
    }

    /**
     * Apply PNG predictor to decoded data.
     *
     * @param array<array<int>> $data    Row data
     * @param int               $columns Number of columns
     *
     * @throws FpdfException
     *
     * @return array<array<int>> Decoded data
     */
    private function applyPngPredictor(array $data, int $columns): array
    {
        $rowlen  = $columns + 1;
        $prevRow = array_fill(0, $rowlen, 0);
        $result  = [];

        foreach ($data as $row) {
            $decodedRow = [];
            $predValue  = 10 + ($row[0] ?? 0);

            for ($i = 1; $i <= $columns; $i++) {
                $j         = $i - 1;
                $rowUp     = $prevRow[$j] ?? 0;
                $rowLeft   = ($i > 1) ? ($row[$i - 1] ?? 0) : 0;
                $rowUpLeft = ($i > 1) ? ($prevRow[$j - 1] ?? 0) : 0;

                $byte = $row[$i] ?? 0;

                switch ($predValue) {
                    case 10: // PNG None
                        $decodedRow[$j] = $byte;

                        break;

                    case 11: // PNG Sub
                        $decodedRow[$j] = ($byte + $rowLeft) & 0xFF;

                        break;

                    case 12: // PNG Up
                        $decodedRow[$j] = ($byte + $rowUp) & 0xFF;

                        break;

                    case 13: // PNG Average
                        $decodedRow[$j] = ($byte + (($rowLeft + $rowUp) / 2)) & 0xFF;

                        break;

                    case 14: // PNG Paeth
                        $p    = ($rowLeft + $rowUp - $rowUpLeft);
                        $pa   = abs($p - $rowLeft);
                        $pb   = abs($p - $rowUp);
                        $pc   = abs($p - $rowUpLeft);
                        $pmin = min($pa, $pb, $pc);

                        if ($pmin === $pa) {
                            $decodedRow[$j] = ($byte + $rowLeft) & 0xFF;
                        } elseif ($pmin === $pb) {
                            $decodedRow[$j] = ($byte + $rowUp) & 0xFF;
                        } else {
                            $decodedRow[$j] = ($byte + $rowUpLeft) & 0xFF;
                        }

                        break;

                    default:
                        throw new FpdfException('Unknown PNG predictor: ' . $predValue);
                }
            }

            $result[] = $decodedRow;
            $prevRow  = $decodedRow;
        }

        return $result;
    }

    /**
     * Get value from dictionary array.
     *
     * Dictionary format: [['/', '/Key'], ['type', value], ...]
     * For arrays: [['/', '/Key'], ['[', [['type', value], ...]]]
     *
     * @param array<int, mixed> $dict Dictionary array
     * @param string            $key  Key to look up
     *
     * @return mixed Value or null if not found
     */
    private function getDictValue(array $dict, string $key): mixed
    {
        $counter = count($dict);

        // Dictionary is an array of [type, value] pairs
        // where keys are at even indices and values at odd indices
        for ($i = 0; $i < $counter; $i += 2) {
            if (!isset($dict[$i]) || !is_array($dict[$i])) {
                continue;
            }

            // Check if this is the key we're looking for
            // Format: ['/', '/KeyName']
            // Normalize both the stored key and the search key (ensure leading slash)
            // Handle case where key might include '[' if PDF parsing incorrectly extracted it
            $storedKey           = $dict[$i][1] ?? '';
            $searchKey           = ltrim($key, '/');
            $storedKeyNormalized = ltrim((string) $storedKey, '/');

            // If stored key contains '[', extract just the key part before '['
            if (str_contains($storedKeyNormalized, '[')) {
                $storedKeyNormalized = substr($storedKeyNormalized, 0, strpos($storedKeyNormalized, '['));
            }

            if (($dict[$i][0] ?? '') === '/' && ($storedKeyNormalized === $searchKey || $storedKey === $key)) {
                // Get the value at the next index
                if (!isset($dict[$i + 1])) {
                    return null;
                }

                $valueEntry = $dict[$i + 1];

                if (!is_array($valueEntry)) {
                    return $valueEntry;
                }

                // Handle array values: ['[', [['type', value], ...]]
                if (($valueEntry[0] ?? '') === '[' && isset($valueEntry[1]) && is_array($valueEntry[1])) {
                    // Array value - extract numeric values from nested array
                    $result = [];

                    foreach ($valueEntry[1] as $item) {
                        if (is_array($item) && isset($item[1])) {
                            // Convert numeric strings back to integers/floats
                            if (($item[0] ?? '') === 'numeric' && is_numeric($item[1])) {
                                $result[] = (int) $item[1]; // For /W array, we expect integers
                            } else {
                                $result[] = $item[1];
                            }
                        } elseif (!is_array($item)) {
                            $result[] = $item;
                        }
                    }

                    return $result;
                }

                return $valueEntry[1] ?? $valueEntry;
            }
        }

        return null;
    }
}
