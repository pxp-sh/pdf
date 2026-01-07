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
namespace PXP\PDF\CCITTFax\Decoder;

use const PHP_INT_MAX;
use function array_fill;
use function fwrite;
use function is_resource;
use function min;
use function sprintf;
use RuntimeException;

use PXP\PDF\CCITTFax\Util\BitBuffer;
use PXP\PDF\CCITTFax\Model\Params;
use PXP\PDF\CCITTFax\Interface\StreamDecoderInterface;
use PXP\PDF\CCITTFax\Constants\Codes;
use PXP\PDF\CCITTFax\Util\BitmapPacker;

/**
 * CCITT Group 3 1D Fax Decoder (T.4 encoding, K=0).
 *
 * Group 3 1D encoding (also known as Modified Huffman or MH):
 * - Each line is independently encoded as a sequence of run-lengths
 * - Lines start with white runs (even if zero length)
 * - Alternates between white and black runs
 * - Each run encoded as Huffman codes from CCITT T.4 tables
 * - Lines may be preceded by EOL marker (12 zeros + 1 bit = 0x001)
 * - Simpler than Group 4 (no reference line, no mode codes)
 *
 * @see https://www.itu.int/rec/T-REC-T.4/en ITU-T T.4 Recommendation
 */
final class CCITT3Decoder implements StreamDecoderInterface
{
    private BitBuffer $bitBuffer;
    private Params $params;

    /** @var array<int, int> Current line being decoded */
    private array $currentLine = [];

    /** Current color being decoded (0 = white, 255 = black) */
    private int $currentColor = 0;

    /** Current position in line (pixel index) */
    private int $a0 = 0;

    /** Number of lines decoded */
    private int $linesDecoded = 0;

    /** Maximum consecutive damaged rows before error */
    private int $consecutiveDamagedRows = 0;

    /**
     * @param Params  $params CCITT Fax parameters
     * @param resource|string $data   Compressed fax data (string or stream resource)
     */
    public function __construct(Params $params, $data)
    {
        $this->params    = $params;
        $this->bitBuffer = new BitBuffer($data);

        // Initialize first line
        $this->initializeLine();
    }

    /**
     * Decode the compressed fax data.
     *
     * @throws RuntimeException on decoding error
     *
     * @return array<int, array<int, int>> Array of pixel lines, each line is array of pixel values (0 or 255)
     */
    public function decode(): array
    {
        $lines = [];

        // Skip initial EOL if present
        if ($this->params->getEndOfLine()) {
            $this->skipToNextEOL();
        }

        $maxRows = $this->params->getRows() > 0 ? $this->params->getRows() : PHP_INT_MAX;

        while ($this->linesDecoded < $maxRows) {
            try {
                // Decode one line
                $line = $this->decodeLine();

                if ($line === null) {
                    // End of data or RTC encountered
                    break;
                }

                $lines[] = $line;
                $this->linesDecoded++;
                $this->consecutiveDamagedRows = 0;

                // Check for end conditions
                if ($this->checkEndOfBlock()) {
                    break;
                }

                // Skip EOL before next line if present
                if ($this->params->getEndOfLine()) {
                    $this->skipToNextEOL();
                }
            } catch (RuntimeException $e) {
                // Handle damaged row
                if ($this->consecutiveDamagedRows >= $this->params->getDamagedRowsBeforeError()) {
                    throw new RuntimeException(
                        sprintf(
                            'Too many consecutive damaged rows (limit: %d): %s',
                            $this->params->getDamagedRowsBeforeError(),
                            $e->getMessage(),
                        ),
                        0,
                        $e,
                    );
                }

                $this->consecutiveDamagedRows++;

                // Try to recover by finding next EOL
                if ($this->params->getEndOfLine()) {
                    $this->skipToNextEOL();
                    $this->initializeLine();

                    continue;
                }

                // Can't recover without EOL markers
                throw $e;
            }
        }

        return $lines;
    }

    /**
     * Decode to stream (streaming method for memory efficiency).
     *
     * @param resource $outputStream Stream to write decoded bitmap data to
     *
     * @throws RuntimeException on decoding error
     *
     * @return int Number of bytes written
     */
    public function decodeToStream($outputStream): int
    {
        if (!is_resource($outputStream)) {
            throw new RuntimeException('Output must be a valid stream resource');
        }

        $bytesWritten = 0;

        // Skip initial EOL if present
        if ($this->params->getEndOfLine()) {
            $this->skipToNextEOL();
        }

        $maxRows = $this->params->getRows() > 0 ? $this->params->getRows() : PHP_INT_MAX;

        while ($this->linesDecoded < $maxRows) {
            try {
                // Decode one line
                $line = $this->decodeLine();

                if ($line === null) {
                    // End of data or RTC encountered
                    break;
                }

                // Write line to stream immediately
                $packed  = BitmapPacker::packLines([$line], $this->params->getColumns());
                $written = fwrite($outputStream, $packed);

                if ($written === false) {
                    throw new RuntimeException('Failed to write to output stream');
                }

                $bytesWritten += $written;
                $this->linesDecoded++;
                $this->consecutiveDamagedRows = 0;

                // Check for end conditions
                if ($this->checkEndOfBlock()) {
                    break;
                }

                // Skip EOL before next line if present
                if ($this->params->getEndOfLine()) {
                    $this->skipToNextEOL();
                }
            } catch (RuntimeException $e) {
                // Handle damaged row
                if ($this->consecutiveDamagedRows >= $this->params->getDamagedRowsBeforeError()) {
                    throw new RuntimeException(
                        sprintf(
                            'Too many consecutive damaged rows (limit: %d): %s',
                            $this->params->getDamagedRowsBeforeError(),
                            $e->getMessage(),
                        ),
                        0,
                        $e,
                    );
                }

                $this->consecutiveDamagedRows++;

                // Try to recover by finding next EOL
                if ($this->params->getEndOfLine()) {
                    $this->skipToNextEOL();
                    $this->initializeLine();

                    continue;
                }

                // Can't recover without EOL markers
                throw $e;
            }
        }

        return $bytesWritten;
    }

    public function getWidth(): int
    {
        return $this->params->getColumns();
    }

    public function getHeight(): int
    {
        return $this->params->getRows();
    }

    /**
     * Decode a single line using Group 3 1D encoding.
     *
     * @throws RuntimeException on decoding error
     *
     * @return null|array<int, int> Pixel line or null if end of data
     */
    private function decodeLine(): ?array
    {
        $this->initializeLine();

        // Check if we've hit all zeros (padding at end)
        [$data] = $this->bitBuffer->peak32();

        if ($data === 0) {
            return null;
        }

        // Decode runs until line is complete
        while ($this->a0 < $this->params->getColumns()) {
            $runLength = $this->decodeRun();

            if ($runLength < 0) {
                // Invalid code detected
                throw new RuntimeException(
                    sprintf(
                        'Invalid run-length code at position %d in line %d',
                        $this->a0,
                        $this->linesDecoded,
                    ),
                );
            }

            // Fill pixels with current color
            $endPos = min($this->a0 + $runLength, $this->params->getColumns());

            for ($i = $this->a0; $i < $endPos; $i++) {
                $this->currentLine[$i] = $this->currentColor;
            }

            $this->a0 = $endPos;

            // Switch color
            $this->currentColor = $this->currentColor === 0 ? 255 : 0;
        }

        // Apply color inversion if needed
        if ($this->params->getBlackIs1()) {
            for ($i = 0; $i < $this->params->getColumns(); $i++) {
                $this->currentLine[$i] = 255 - $this->currentLine[$i];
            }
        }

        return $this->currentLine;
    }

    /**
     * Decode a single run-length (white or black).
     *
     * In CCITT T.4, runs are encoded as:
     * - Make-up code (optional, for runs >= 64): encodes multiples of 64
     * - Terminating code (required): encodes remainder 0-63
     *
     * @return int Run length in pixels, or -1 on error
     */
    private function decodeRun(): int
    {
        $runLength = 0;
        $isWhite   = ($this->currentColor === 0);

        // Decode make-up codes (multiples of 64)
        while (true) {
            [$data] = $this->bitBuffer->peak16();
            $code   = Codes::findCode($data, $isWhite, true); // true = look for make-up codes

            if ($code === null) {
                // No make-up code, try terminating code
                break;
            }

            $this->bitBuffer->flushBits($code->getBitsUsed());
            $runLength += $code->getRunLength();

            // If run length from make-up code is < 64, it's actually the end
            // (shouldn't happen with proper encoding, but handle it)
            if ($code->getRunLength() < 64) {
                return $runLength;
            }
        }

        // Decode terminating code (0-63)
        [$data] = $this->bitBuffer->peak16();
        $code   = Codes::findCode($data, $isWhite, false); // false = look for terminating codes

        if ($code === null) {
            return -1; // Invalid code
        }

        $this->bitBuffer->flushBits($code->getBitsUsed());
        $runLength += $code->getRunLength();

        return $runLength;
    }

    /**
     * Initialize line state for decoding.
     */
    private function initializeLine(): void
    {
        $this->currentLine  = array_fill(0, $this->params->getColumns(), 0);
        $this->currentColor = 0; // Always start with white
        $this->a0           = 0;
    }

    /**
     * Skip to the next EOL marker.
     *
     * EOL = 12 zero bits followed by a 1 bit (0x001 when read as 16 bits with proper alignment)
     * If EncodedByteAlign is true, EOL may be padded with fill bits to byte boundary
     */
    private function skipToNextEOL(): void
    {
        $maxAttempts = $this->params->getColumns() * 2; // Reasonable limit to avoid infinite loop
        $attempts    = 0;

        while ($attempts < $maxAttempts) {
            [$data] = $this->bitBuffer->peak16();

            // Check for EOL pattern: 12 zeros + 1 bit = 0x0001 in top 13 bits
            // Pattern is: 000000000001xxxx (where x = don't care)
            if (($data & 0xFFF0) === 0x0010 || ($data & 0xFFE0) === 0x0020 || ($data & 0xFFC0) === 0x0040) {
                // Found potential EOL, determine exact bit position
                for ($shift = 0; $shift < 16; $shift++) {
                    if ((($data >> $shift) & 0x1FFF) === 0x0001) {
                        // Found EOL at this shift
                        $this->bitBuffer->flushBits(13 + $shift);

                        // Handle byte alignment if needed
                        if ($this->params->getEncodedByteAlign()) {
                            $this->alignToNextByte();
                        }

                        return;
                    }
                }
            }

            // Not found, advance one bit and try again
            $this->bitBuffer->flushBits(1);
            $attempts++;
        }

        // Didn't find EOL - might be at end of data
    }

    /**
     * Align bit position to next byte boundary.
     */
    private function alignToNextByte(): void
    {
        $bitsToFlush = (8 - ($this->bitBuffer->getBitsRead() % 8)) % 8;

        if ($bitsToFlush > 0) {
            $this->bitBuffer->flushBits($bitsToFlush);
        }
    }

    /**
     * Check for end-of-block marker (RTC = Return To Control).
     *
     * For Group 3: RTC = 6 consecutive EOL codes
     *
     * @return bool True if EOB detected
     */
    private function checkEndOfBlock(): bool
    {
        if (!$this->params->getEndOfBlock()) {
            return false;
        }

        // For Group 3, check for 6 consecutive EOL markers
        // This is a simplified check - proper implementation would peek ahead
        // For now, we'll check at the current position
        [$saved] = $this->bitBuffer->peak32();

        // Simplified: look for multiple EOLs in sequence
        // Pattern: (000000000001){6} but this is complex to match perfectly
        // We'll check if we see mostly zeros which indicates RTC region
        if ($saved === 0x00000000) {
            // Likely padding after RTC
            return true;
        }

        return false;
    }
}