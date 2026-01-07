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

use function array_fill;
use function count;
use function fwrite;
use function is_resource;
use PXP\PDF\CCITTFax\Constants\Codes;
use PXP\PDF\CCITTFax\Constants\Modes;
use PXP\PDF\CCITTFax\Interface\StreamDecoderInterface;
use PXP\PDF\CCITTFax\Model\Mode;
use PXP\PDF\CCITTFax\Model\ModeCode;
use PXP\PDF\CCITTFax\Util\BitBuffer;
use PXP\PDF\CCITTFax\Util\BitmapPacker;
use RuntimeException;

class CCITT4Decoder implements StreamDecoderInterface
{
    private readonly BitBuffer $bitBuffer;
    private readonly Modes $modes;
    private readonly Codes $horizontalCodes;

    /**
     * @param int             $width        Image width in pixels
     * @param resource|string $bytes        Compressed data (string or stream resource)
     * @param bool            $reverseColor Whether to reverse colors (BlackIs1)
     */
    public function __construct(private readonly int $width, $bytes, private readonly bool $reverseColor = false)
    {
        $this->bitBuffer       = new BitBuffer($bytes);
        $this->modes           = new Modes;
        $this->horizontalCodes = new Codes;

        // Skip any leading fill bits (0x00 bytes) at the beginning
        $this->skipFillBits();
    }

    /**
     * Decode to memory (legacy method).
     *
     * @return array<int, array<int, int>>
     */
    public function decode(): array
    {
        $lines   = [];
        $line    = array_fill(0, $this->width, 0);
        $linePos = 0;
        $curLine = 0;
        $a0Color = 255; // start white

        while ($this->bitBuffer->hasData()) {
            if ($linePos > $this->width - 1) {
                $lines[] = $line;
                $line    = array_fill(0, $this->width, 0);
                $linePos = 0;
                $a0Color = 255; // start white
                $curLine++;

                if ($this->endOfBlock($this->bitBuffer->getBuffer())) {
                    break;
                }
            }

            // end on trailing zeros padding
            [$v] = $this->bitBuffer->peak32();

            if ($v === 0x00000000) {
                break;
            }

            // mode lookup
            try {
                $mode = $this->getMode();
            } catch (RuntimeException $e) {
                // If we can't get a valid mode and we have lines, treat as end of stream
                if (count($lines) > 0) {
                    break;
                }

                throw $e;
            }
            $this->bitBuffer->flushBits($mode->bitsUsed);

            // act on mode
            switch ($mode->type) {
                case Mode::Pass:
                    [, $b2] = $this->findBValues(
                        $this->getPreviousLine($lines, $curLine),
                        $linePos,
                        $a0Color,
                        false,
                    );

                    for ($p = $linePos; $p < $b2; $p++) {
                        $line[$linePos] = $a0Color;
                        $linePos++;
                    }

                    // a0 color should stay the same
                    break;

                case Mode::Extension:
                    // Extension mode is rarely used - skip it and continue
                    // In most cases, this indicates a malformed stream that can be ignored
                    continue 2;

                case Mode::Horizontal:
                    $isWhite = $a0Color === 255;

                    $length = [0, 0];
                    $color  = [127, 127];

                    for ($i = 0; $i < 2; $i++) {
                        $scan = true;

                        while ($scan) {
                            $h = $this->horizontalCodes->findMatch32($this->bitBuffer->getBuffer(), $isWhite);
                            $this->bitBuffer->flushBits($h->bitsUsed);
                            $length[$i] += $h->pixels;
                            $color[$i] = $h->color;

                            if ($h->terminating) {
                                $isWhite = !$isWhite;
                                $scan    = false;
                            }
                        }
                    }

                    for ($i = 0; $i < 2; $i++) {
                        for ($p = 0; $p < $length[$i]; $p++) {
                            if ($linePos < count($line)) {
                                $line[$linePos] = $color[$i];
                            }
                            $linePos++;
                        }
                    }

                    // a0 color should stay the same
                    break;

                case Mode::VerticalZero:
                case Mode::VerticalL1:
                case Mode::VerticalR1:
                case Mode::VerticalL2:
                case Mode::VerticalR2:
                case Mode::VerticalL3:
                case Mode::VerticalR3:
                    $offset = $mode->getVerticalOffset();
                    [$b1]   = $this->findBValues(
                        $this->getPreviousLine($lines, $curLine),
                        $linePos,
                        $a0Color,
                        true,
                    );

                    for ($i = $linePos; $i < $b1 + $offset; $i++) {
                        if ($linePos < count($line)) {
                            $line[$linePos] = $a0Color;
                        }
                        $linePos++;
                    }

                    // a0 color changes
                    $a0Color = $this->reverseColorValue($a0Color);

                    break;

                default:
                    throw new RuntimeException('unknown mode type');
            }
        }

        if ($this->reverseColor) {
            $counter = count($lines);

            for ($i = 0; $i < $counter; $i++) {
                for ($x = 0; $x < count($lines[$i]); $x++) {
                    $lines[$i][$x] = $this->reverseColorValue($lines[$i][$x]);
                }
            }
        }

        return $lines;
    }

    /**
     * Decode to stream (streaming method for memory efficiency).
     *
     * @param resource $outputStream Stream to write decoded bitmap data to
     *
     * @return int Number of bytes written
     */
    public function decodeToStream($outputStream): int
    {
        if (!is_resource($outputStream)) {
            throw new RuntimeException('Output must be a valid stream resource');
        }

        $bytesWritten  = 0;
        $line          = array_fill(0, $this->width, 0);
        $referenceLine = array_fill(0, $this->width, 255); // Previous line for 2D coding
        $linePos       = 0;
        $curLine       = 0;
        $a0Color       = 255; // start white

        while ($this->bitBuffer->hasData()) {
            if ($linePos > $this->width - 1) {
                // Write completed line to stream
                $packed  = BitmapPacker::packLines([$line], $this->width);
                $written = fwrite($outputStream, $packed);

                if ($written === false) {
                    throw new RuntimeException('Failed to write to output stream');
                }

                $bytesWritten += $written;

                // Store as reference for next line
                $referenceLine = $line;

                // Reset for next line
                $line    = array_fill(0, $this->width, 0);
                $linePos = 0;
                $a0Color = 255; // start white
                $curLine++;

                if ($this->endOfBlock($this->bitBuffer->getBuffer())) {
                    break;
                }
            }

            // end on trailing zeros padding
            [$v] = $this->bitBuffer->peak32();

            if ($v === 0x00000000) {
                break;
            }

            // mode lookup
            try {
                $mode = $this->getMode();
            } catch (RuntimeException $e) {
                // If we can't get a valid mode and we have lines, treat as end of stream
                if ($curLine > 0) {
                    break;
                }

                throw $e;
            }
            $this->bitBuffer->flushBits($mode->bitsUsed);

            // act on mode
            switch ($mode->type) {
                case Mode::Pass:
                    [, $b2] = $this->findBValues(
                        $referenceLine,
                        $linePos,
                        $a0Color,
                        false,
                    );

                    for ($p = $linePos; $p < $b2; $p++) {
                        $line[$linePos] = $a0Color;
                        $linePos++;
                    }

                    // a0 color should stay the same
                    break;

                case Mode::Extension:
                    // Extension mode is rarely used - skip it and continue
                    // In most cases, this indicates a malformed stream that can be ignored
                    continue 2;

                case Mode::Horizontal:
                    $isWhite = $a0Color === 255;

                    $length = [0, 0];
                    $color  = [127, 127];

                    for ($i = 0; $i < 2; $i++) {
                        $scan = true;

                        while ($scan) {
                            $h = $this->horizontalCodes->findMatch32($this->bitBuffer->getBuffer(), $isWhite);
                            $this->bitBuffer->flushBits($h->bitsUsed);
                            $length[$i] += $h->pixels;
                            $color[$i] = $h->color;

                            if ($h->terminating) {
                                $isWhite = !$isWhite;
                                $scan    = false;
                            }
                        }
                    }

                    for ($i = 0; $i < 2; $i++) {
                        for ($p = 0; $p < $length[$i]; $p++) {
                            if ($linePos < count($line)) {
                                $line[$linePos] = $color[$i];
                            }
                            $linePos++;
                        }
                    }

                    // a0 color should stay the same
                    break;

                case Mode::VerticalZero:
                case Mode::VerticalL1:
                case Mode::VerticalR1:
                case Mode::VerticalL2:
                case Mode::VerticalR2:
                case Mode::VerticalL3:
                case Mode::VerticalR3:
                    $offset = $mode->getVerticalOffset();
                    [$b1]   = $this->findBValues(
                        $referenceLine,
                        $linePos,
                        $a0Color,
                        true,
                    );

                    for ($i = $linePos; $i < $b1 + $offset; $i++) {
                        if ($linePos < count($line)) {
                            $line[$linePos] = $a0Color;
                        }
                        $linePos++;
                    }

                    // a0 color changes
                    $a0Color = $this->reverseColorValue($a0Color);

                    break;

                default:
                    throw new RuntimeException('unknown mode type');
            }
        }

        return $bytesWritten;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return 0; // Height not known beforehand for Group 4
    }

    private function reverseColorValue(int $current): int
    {
        return $current === 0 ? 255 : 0;
    }

    private function endOfBlock(int $buffer): bool
    {
        return ($buffer & 0xFFFFFF00) === 0x00100100;
    }

    /**
     * @param array<int, int> $refLine Reference line (previous line for streaming mode)
     *
     * @return array{int, int}
     */
    private function findBValues(array $refLine, int $a0pos, int $a0Color, bool $justb1): array
    {
        $other    = $this->reverseColorValue($a0Color);
        $startPos = $a0pos;

        if ($startPos !== 0) {
            $startPos++;
        }

        $b1      = 0;
        $b2      = 0;
        $counter = count($refLine);

        for ($i = $startPos; $i < $counter; $i++) {
            if ($i === 0) {
                $curColor  = $refLine[0];
                $lastColor = 255;
            } else {
                $curColor  = $refLine[$i];
                $lastColor = $refLine[$i - 1];
            }

            if ($b1 !== 0 && ($curColor === $a0Color && $lastColor === $other)) {
                $b2 = $i;

                return [$b1, $b2];
            }

            if ($curColor === $other && $lastColor === $a0Color) {
                $b1 = $i;

                if ($justb1) {
                    return [$b1, $b2];
                }
            }
        }

        if ($b1 === 0) {
            $b1 = count($refLine);
        } else {
            $b2 = count($refLine);
        }

        return [$b1, $b2];
    }

    /**
     * Get previous line from lines array (for legacy decode() method).
     *
     * @param array<int, array<int, int>> $lines       Array of decoded lines
     * @param int                         $currentLine Current line index
     *
     * @return array<int, int> Previous line or white line for first line
     */
    private function getPreviousLine(array $lines, int $currentLine): array
    {
        if ($currentLine === 0) {
            return array_fill(0, $this->width, 255);
        }

        return $lines[$currentLine - 1];
    }

    private function getMode(): ModeCode
    {
        [$b8, $valid] = $this->bitBuffer->peak8();

        if (!$valid) {
            throw new RuntimeException('Unexpected end of stream while reading mode code');
        }

        // Skip fill bits (0x00 bytes) - these are valid padding in CCITT streams
        while ($b8 === 0 && $this->bitBuffer->available() >= 8) {
            $this->bitBuffer->getBits(8); // Consume the 0x00 byte
            [$b8, $valid] = $this->bitBuffer->peak8();

            if (!$valid) {
                throw new RuntimeException('End of stream after fill bits');
            }
        }

        if ($b8 === 0) {
            throw new RuntimeException('Invalid mode code 0x00 at end of stream');
        }

        return $this->modes->getMode($b8);
    }

    /**
     * Skip leading fill bits (0x00 bytes) at the beginning of the stream.
     * Fill bits are used for byte alignment and padding in CCITT streams.
     */
    private function skipFillBits(): void
    {
        while ($this->bitBuffer->available() >= 8) {
            [$b8] = $this->bitBuffer->peak8();

            if ($b8 === 0) {
                $this->bitBuffer->getBits(8); // Consume the 0x00 byte
            } else {
                break; // Found non-zero byte, stop skipping
            }
        }
    }
}
