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
use PXP\PDF\CCITTFax\Model\Mode;
use PXP\PDF\CCITTFax\Constants\Modes;
use PXP\PDF\CCITTFax\Constants\Codes;
use PXP\PDF\CCITTFax\Util\BitmapPacker;

/**
 * CCITT Group 3 Mixed 1D/2D Fax Decoder (T.4 with 2D extensions, K>0).
 *
 * Mixed mode encoding alternates between:
 * - 1D encoded lines (Modified Huffman)
 * - 2D encoded lines (Read coding, like Group 4)
 *
 * The K parameter specifies maximum consecutive 2D-encoded lines.
 * Every K+1-th line must be 1D encoded (reference line).
 *
 * @see https://www.itu.int/rec/T-REC-T.4/en ITU-T T.4 Recommendation
 */
final class CCITT3MixedDecoder implements StreamDecoderInterface
{
    private BitBuffer $bitBuffer;
    private Params $params;
    private Modes $modeTable;

    /** @var array<int, int> Reference line for 2D decoding */
    private array $referenceLine = [];

    /** Number of consecutive 2D lines decoded */
    private int $consecutive2DLines = 0;

    /** Number of lines decoded */
    private int $linesDecoded = 0;

    /**
     * @param Params  $params CCITT Fax parameters (K must be > 0)
     * @param resource|string $data   Compressed fax data (string or stream resource)
     */
    public function __construct(Params $params, $data)
    {
        if ($params->getK() <= 0) {
            throw new RuntimeException('Mixed mode requires K > 0');
        }

        $this->params    = $params;
        $this->bitBuffer = new BitBuffer($data);
        $this->modeTable = new Modes;

        // Initialize reference line (all white)
        $this->referenceLine = array_fill(0, $params->getColumns(), 0);
    }

    /**
     * Decode the compressed fax data.
     *
     * @throws RuntimeException on decoding error
     *
     * @return array<int, array<int, int>> Array of pixel lines
     */
    public function decode(): array
    {
        $lines = [];

        // Skip initial EOL if present
        if ($this->params->getEndOfLine()) {
            $this->skipToNextEOL();
        }

        $maxRows = $this->params->getRows() > 0 ? $this->params->getRows() : PHP_INT_MAX;
        $k       = $this->params->getK();

        while ($this->linesDecoded < $maxRows) {
            try {
                // Determine if this line is 1D or 2D encoded
                $is1D = false;

                if ($this->params->getEndOfLine()) {
                    // After EOL, next bit indicates encoding type
                    // 1 = 1D, 0 = 2D
                    [$tagBit] = $this->bitBuffer->peak16();
                    $is1D     = (($tagBit & 0x8000) !== 0);
                    $this->bitBuffer->flushBits(1);
                } else {
                    // Without EOL markers, use K parameter
                    // Every (K+1)th line must be 1D
                    $is1D = ($this->consecutive2DLines >= $k);
                }

                // Decode line based on type
                if ($is1D) {
                    $line                     = $this->decode1DLine();
                    $this->consecutive2DLines = 0;
                } else {
                    $line = $this->decode2DLine();
                    $this->consecutive2DLines++;
                }

                if ($line === null) {
                    break;
                }

                $lines[]             = $line;
                $this->referenceLine = $line; // Update reference for next 2D line
                $this->linesDecoded++;

                // Check for end conditions
                if ($this->checkEndOfBlock()) {
                    break;
                }

                // Skip EOL before next line if present
                if ($this->params->getEndOfLine()) {
                    $this->skipToNextEOL();
                }
            } catch (RuntimeException $e) {
                // Handle damaged row with error recovery
                throw new RuntimeException(
                    sprintf(
                        'Failed to decode line %d in mixed mode: %s',
                        $this->linesDecoded,
                        $e->getMessage(),
                    ),
                    0,
                    $e,
                );
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
        $k       = $this->params->getK();

        while ($this->linesDecoded < $maxRows) {
            try {
                // Determine if this line is 1D or 2D encoded
                $is1D = false;

                if ($this->params->getEndOfLine()) {
                    // After EOL, next bit indicates encoding type
                    // 1 = 1D, 0 = 2D
                    [$tagBit] = $this->bitBuffer->peak16();
                    $is1D     = (($tagBit & 0x8000) !== 0);
                    $this->bitBuffer->flushBits(1);
                } else {
                    // Without EOL markers, use K parameter
                    // Every (K+1)th line must be 1D
                    $is1D = ($this->consecutive2DLines >= $k);
                }

                // Decode line based on type
                if ($is1D) {
                    $line                     = $this->decode1DLine();
                    $this->consecutive2DLines = 0;
                } else {
                    $line = $this->decode2DLine();
                    $this->consecutive2DLines++;
                }

                if ($line === null) {
                    break;
                }

                // Write line to stream immediately
                $packed  = BitmapPacker::packLines([$line], $this->params->getColumns());
                $written = fwrite($outputStream, $packed);

                if ($written === false) {
                    throw new RuntimeException('Failed to write to output stream');
                }

                $bytesWritten += $written;
                $this->referenceLine = $line; // Update reference for next 2D line
                $this->linesDecoded++;

                // Check for end conditions
                if ($this->checkEndOfBlock()) {
                    break;
                }

                // Skip EOL before next line if present
                if ($this->params->getEndOfLine()) {
                    $this->skipToNextEOL();
                }
            } catch (RuntimeException $e) {
                // Handle damaged row with error recovery
                throw new RuntimeException(
                    sprintf(
                        'Failed to decode line %d in mixed mode: %s',
                        $this->linesDecoded,
                        $e->getMessage(),
                    ),
                    0,
                    $e,
                );
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
     * Decode a 1D encoded line (Modified Huffman).
     *
     * @return null|array<int, int> Pixel line or null if end of data
     */
    private function decode1DLine(): ?array
    {
        [$data] = $this->bitBuffer->peak32();

        if ($data === 0) {
            return null;
        }

        $line         = array_fill(0, $this->params->getColumns(), 0);
        $a0           = 0;
        $currentColor = 0; // White

        // Decode runs until line is complete
        while ($a0 < $this->params->getColumns()) {
            $runLength = $this->decodeRun($currentColor === 0);

            if ($runLength < 0) {
                throw new RuntimeException(
                    sprintf('Invalid 1D run-length code at position %d', $a0),
                );
            }

            // Fill pixels
            $endPos = min($a0 + $runLength, $this->params->getColumns());

            for ($i = $a0; $i < $endPos; $i++) {
                $line[$i] = $currentColor;
            }

            $a0           = $endPos;
            $currentColor = $currentColor === 0 ? 255 : 0;
        }

        // Apply color inversion if needed
        if ($this->params->getBlackIs1()) {
            for ($i = 0; $i < $this->params->getColumns(); $i++) {
                $line[$i] = 255 - $line[$i];
            }
        }

        return $line;
    }

    /**
     * Decode a 2D encoded line (Read coding).
     *
     * @return null|array<int, int> Pixel line or null if end of data
     */
    private function decode2DLine(): ?array
    {
        [$data] = $this->bitBuffer->peak32();

        if ($data === 0) {
            return null;
        }

        $line         = array_fill(0, $this->params->getColumns(), 0);
        $a0           = 0;
        $currentColor = 0; // White

        // Decode using 2D modes with reference line
        while ($a0 < $this->params->getColumns()) {
            // Get mode code
            [$modeData] = $this->bitBuffer->peak16();
            $mode       = $this->modeTable->getMode($modeData);

            $this->bitBuffer->flushBits($mode->getBitsUsed());

            // Process mode
            switch ($mode->getType()) {
                case Mode::Pass:
                    $b2 = $this->findB2($a0, $currentColor);

                    for ($i = $a0; $i < $b2 && $i < $this->params->getColumns(); $i++) {
                        $line[$i] = $currentColor;
                    }
                    $a0 = $b2;

                    break;

                case Mode::Horizontal:
                    // Decode two runs
                    $run1 = $this->decodeRun($currentColor === 0);
                    $run2 = $this->decodeRun($currentColor !== 0);

                    $endPos1 = min($a0 + $run1, $this->params->getColumns());

                    for ($i = $a0; $i < $endPos1; $i++) {
                        $line[$i] = $currentColor;
                    }

                    $oppositeColor = $currentColor === 0 ? 255 : 0;
                    $endPos2       = min($endPos1 + $run2, $this->params->getColumns());

                    for ($i = $endPos1; $i < $endPos2; $i++) {
                        $line[$i] = $oppositeColor;
                    }

                    $a0 = $endPos2;

                    break;

                case Mode::VerticalZero:
                case Mode::VerticalR1:
                case Mode::VerticalR2:
                case Mode::VerticalR3:
                case Mode::VerticalL1:
                case Mode::VerticalL2:
                case Mode::VerticalL3:
                    $b1 = $this->findB1($a0, $currentColor);
                    $a1 = $b1 + $mode->getVerticalOffset();

                    for ($i = $a0; $i < $a1 && $i < $this->params->getColumns(); $i++) {
                        $line[$i] = $currentColor;
                    }

                    $a0           = $a1;
                    $currentColor = $currentColor === 0 ? 255 : 0;

                    break;

                case Mode::Extension:
                    // Skip extension codes
                    break;

                default:
                    throw new RuntimeException('Unknown 2D mode type: ' . $mode->getType()->value);
            }
        }

        // Apply color inversion if needed
        if ($this->params->getBlackIs1()) {
            for ($i = 0; $i < $this->params->getColumns(); $i++) {
                $line[$i] = 255 - $line[$i];
            }
        }

        return $line;
    }

    /**
     * Decode a run-length (reused from 1D logic).
     */
    private function decodeRun(bool $isWhite): int
    {
        $runLength = 0;

        // Decode make-up codes
        while (true) {
            [$data] = $this->bitBuffer->peak16();
            $code   = Codes::findCode($data, $isWhite, true);

            if ($code === null) {
                break;
            }

            $this->bitBuffer->flushBits($code->getBitsUsed());
            $runLength += $code->getRunLength();

            if ($code->getRunLength() < 64) {
                return $runLength;
            }
        }

        // Decode terminating code
        [$data] = $this->bitBuffer->peak16();
        $code   = Codes::findCode($data, $isWhite, false);

        if ($code === null) {
            return -1;
        }

        $this->bitBuffer->flushBits($code->getBitsUsed());
        $runLength += $code->getRunLength();

        return $runLength;
    }

    /**
     * Find b1 position (first changing element to the right of a0).
     */
    private function findB1(int $a0, int $currentColor): int
    {
        $oppositeColor = $currentColor === 0 ? 255 : 0;

        for ($i = $a0; $i < $this->params->getColumns(); $i++) {
            if ($this->referenceLine[$i] === $oppositeColor) {
                return $i;
            }
        }

        return $this->params->getColumns();
    }

    /**
     * Find b2 position (first changing element to the right of b1).
     */
    private function findB2(int $a0, int $currentColor): int
    {
        $b1            = $this->findB1($a0, $currentColor);
        $oppositeColor = $currentColor === 0 ? 255 : 0;

        for ($i = $b1; $i < $this->params->getColumns(); $i++) {
            if ($this->referenceLine[$i] !== $oppositeColor) {
                return $i;
            }
        }

        return $this->params->getColumns();
    }

    private function skipToNextEOL(): void
    {
        $maxAttempts = $this->params->getColumns() * 2;
        $attempts    = 0;

        while ($attempts < $maxAttempts) {
            [$data] = $this->bitBuffer->peak16();

            if (($data & 0xFFF0) === 0x0010 || ($data & 0xFFE0) === 0x0020 || ($data & 0xFFC0) === 0x0040) {
                for ($shift = 0; $shift < 16; $shift++) {
                    if ((($data >> $shift) & 0x1FFF) === 0x0001) {
                        $this->bitBuffer->flushBits(13 + $shift);

                        if ($this->params->getEncodedByteAlign()) {
                            $this->alignToNextByte();
                        }

                        return;
                    }
                }
            }

            $this->bitBuffer->flushBits(1);
            $attempts++;
        }
    }

    private function alignToNextByte(): void
    {
        $bitsToFlush = (8 - ($this->bitBuffer->getBitsRead() % 8)) % 8;

        if ($bitsToFlush > 0) {
            $this->bitBuffer->flushBits($bitsToFlush);
        }
    }

    private function checkEndOfBlock(): bool
    {
        if (!$this->params->getEndOfBlock()) {
            return false;
        }

        [$saved] = $this->bitBuffer->peak32();

        if ($saved === 0x00000000) {
            return true;
        }

        return false;
    }
}