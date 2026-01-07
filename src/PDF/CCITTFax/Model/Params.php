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
namespace PXP\PDF\CCITTFax\Model;

/**
 * CCITT Fax decoder parameters as defined in PDF 1.7 spec (Table 3.9).
 *
 * Encapsulates all CCITTFaxDecode filter parameters:
 * - K: Encoding scheme selector (Group 3 1D, 2D, Group 4)
 * - EndOfLine: Whether EOL codes are present
 * - EncodedByteAlign: Whether EOL codes are byte-aligned
 * - Columns: Width of the image in pixels
 * - Rows: Height of the image in pixels
 * - EndOfBlock: Whether end-of-block pattern (RTC) is present
 * - BlackIs1: Whether 1 bits represent black pixels
 * - DamagedRowsBeforeError: Error tolerance for damaged rows
 */
final readonly class Params
{
    /**
     * Create from PDF DecodeParms dictionary values.
     *
     * @param array<string, mixed> $params dictionary with /K, /Columns, /Rows, etc
     */
    public static function fromArray(array $params): self
    {
        return new self(
            k: isset($params['K']) ? (int) $params['K'] : -1,
            endOfLine: isset($params['EndOfLine']) && (bool) $params['EndOfLine'],
            encodedByteAlign: isset($params['EncodedByteAlign']) && (bool) $params['EncodedByteAlign'],
            columns: isset($params['Columns']) ? (int) $params['Columns'] : 1728,
            rows: isset($params['Rows']) ? (int) $params['Rows'] : 0,
            endOfBlock: isset($params['EndOfBlock']) ? (bool) $params['EndOfBlock'] : true,
            blackIs1: isset($params['BlackIs1']) && (bool) $params['BlackIs1'],
            damagedRowsBeforeError: isset($params['DamagedRowsBeforeError']) ? (int) $params['DamagedRowsBeforeError'] : 0,
        );
    }

    /**
     * @param int  $k                      Encoding scheme:
     *                                     < 0 = Pure 2D (Group 4)
     *                                     = 0 = Pure 1D (Group 3)
     *                                     > 0 = Mixed 1D/2D (Group 3 with 2D extensions)
     *                                     K indicates max consecutive 2D-encoded lines
     * @param bool $endOfLine              If true, EOL codes (12 zeros + 1 bit) are present before each line
     * @param bool $encodedByteAlign       If true, EOL codes are padded to byte boundaries with fill bits
     * @param int  $columns                Width of the image in pixels (default 1728 = standard fax width)
     * @param int  $rows                   Height of the image in scan lines (0 = unknown, decode until EOB or end of data)
     * @param bool $endOfBlock             If true, encoded data contains RTC (Return To Control) marker
     *                                     RTC = 6 consecutive EOL codes for Group 3, or EOFB for Group 4
     * @param bool $blackIs1               If true, 1 bits represent black pixels; if false, 0 bits represent black
     * @param int  $damagedRowsBeforeError Number of damaged rows tolerated before error
     *                                     0 = no errors tolerated (default)
     */
    public function __construct(
        private int $k = -1,
        private bool $endOfLine = false,
        private bool $encodedByteAlign = false,
        private int $columns = 1728,
        private int $rows = 0,
        private bool $endOfBlock = true,
        private bool $blackIs1 = false,
        private int $damagedRowsBeforeError = 0,
    ) {
    }

    public function getK(): int
    {
        return $this->k;
    }

    public function getEndOfLine(): bool
    {
        return $this->endOfLine;
    }

    public function getEncodedByteAlign(): bool
    {
        return $this->encodedByteAlign;
    }

    public function getColumns(): int
    {
        return $this->columns;
    }

    public function getRows(): int
    {
        return $this->rows;
    }

    public function getEndOfBlock(): bool
    {
        return $this->endOfBlock;
    }

    public function getBlackIs1(): bool
    {
        return $this->blackIs1;
    }

    public function getDamagedRowsBeforeError(): int
    {
        return $this->damagedRowsBeforeError;
    }

    /**
     * Determine encoding type.
     */
    public function isGroup4(): bool
    {
        return $this->k < 0;
    }

    /**
     * Determine if pure 1D encoding (Group 3 1D).
     */
    public function isPure1D(): bool
    {
        return $this->k === 0;
    }

    /**
     * Determine if mixed 1D/2D encoding (Group 3 with 2D extensions).
     */
    public function isMixed(): bool
    {
        return $this->k > 0;
    }
}
