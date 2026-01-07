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
namespace PXP\PDF\CCITTFax\Util;

use function ceil;
use function chr;
use function fwrite;
use function is_resource;
use function ord;
use function strlen;
use RuntimeException;

/**
 * Bitmap output formatter and packer.
 *
 * Converts decoded pixel arrays to packed binary formats:
 * - Pack 8 pixels per byte (MSB first) for efficient storage
 * - Convert to uncompressed bitmap strings for PDF integration
 * - Handle color inversion (BlackIs1 parameter)
 * - Support streaming output to avoid memory overflow
 */
final class BitmapPacker
{
    /**
     * Pack pixel lines into bytes (8 pixels per byte, MSB first).
     *
     * Standard CCITT fax format packs 8 pixels per byte:
     * - MSB (bit 7) = leftmost pixel
     * - LSB (bit 0) = rightmost pixel
     * - 1 bit = black pixel (by default)
     * - 0 bit = white pixel (by default)
     *
     * @param array<int, array<int, int>> $lines Array of pixel lines (each pixel is 0 or 255)
     * @param int                         $width Width in pixels (must match line width)
     *
     * @return string Packed binary data
     */
    public static function packLines(array $lines, int $width): string
    {
        $result       = '';
        $bytesPerLine = (int) ceil($width / 8);

        foreach ($lines as $line) {
            $lineData = '';
            $byte     = 0;
            $bitPos   = 7;

            for ($col = 0; $col < $width; $col++) {
                // Pixel value: 255 = black, 0 = white
                // Packed bit: 1 = black, 0 = white
                $pixelBit = ($line[$col] === 255) ? 1 : 0;

                $byte |= ($pixelBit << $bitPos);
                $bitPos--;

                if ($bitPos < 0 || $col === $width - 1) {
                    // Byte is complete or end of line
                    $lineData .= chr($byte);
                    $byte   = 0;
                    $bitPos = 7;
                }
            }

            // Pad line to bytesPerLine if needed
            while (strlen($lineData) < $bytesPerLine) {
                $lineData .= chr(0);
            }

            $result .= $lineData;
        }

        return $result;
    }

    /**
     * Convert packed bytes to unpacked pixel array (for testing/debugging).
     *
     * @param string $data   Packed binary data
     * @param int    $width  Width in pixels
     * @param int    $height Height in lines
     *
     * @return array<int, array<int, int>> Array of pixel lines
     */
    public static function unpackData(string $data, int $width, int $height): array
    {
        $lines        = [];
        $bytesPerLine = (int) ceil($width / 8);
        $offset       = 0;

        for ($row = 0; $row < $height; $row++) {
            $line = [];

            for ($byteIdx = 0; $byteIdx < $bytesPerLine; $byteIdx++) {
                if ($offset >= strlen($data)) {
                    // Pad with white if data is short
                    $byte = 0;
                } else {
                    $byte = ord($data[$offset]);
                }
                $offset++;

                // Extract 8 pixels from byte (MSB first)
                for ($bitPos = 7; $bitPos >= 0; $bitPos--) {
                    $col = $byteIdx * 8 + (7 - $bitPos);

                    if ($col < $width) {
                        $pixelBit   = ($byte >> $bitPos) & 1;
                        $line[$col] = $pixelBit === 1 ? 255 : 0;
                    }
                }
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Convert pixel lines to uncompressed format (1 byte per pixel).
     *
     * This is less efficient but simpler for some use cases.
     * Used by current StreamDecoder implementation.
     *
     * @param array<int, array<int, int>> $lines Array of pixel lines
     *
     * @return string Binary data (1 byte per pixel)
     */
    public static function toUncompressedBytes(array $lines): string
    {
        $result = '';

        foreach ($lines as $line) {
            foreach ($line as $pixel) {
                $result .= chr($pixel);
            }
        }

        return $result;
    }

    /**
     * Calculate packed data size in bytes.
     *
     * @param int $width  Width in pixels
     * @param int $height Height in lines
     *
     * @return int Size in bytes
     */
    public static function calculatePackedSize(int $width, int $height): int
    {
        $bytesPerLine = (int) ceil($width / 8);

        return $bytesPerLine * $height;
    }

    /**
     * Invert colors in pixel array (for BlackIs1=false).
     *
     * @param array<int, array<int, int>> $lines Array of pixel lines
     *
     * @return array<int, array<int, int>> Inverted pixel lines
     */
    public static function invertColors(array $lines): array
    {
        $inverted = [];

        foreach ($lines as $line) {
            $invertedLine = [];

            foreach ($line as $pixel) {
                $invertedLine[] = 255 - $pixel;
            }
            $inverted[] = $invertedLine;
        }

        return $inverted;
    }

    /**
     * Pack and write pixel lines to a stream (streaming mode).
     *
     * This method writes packed bitmap data directly to a stream resource,
     * avoiding the need to hold all data in memory.
     *
     * @param resource                    $stream Stream resource to write to
     * @param array<int, array<int, int>> $lines  Array of pixel lines (each pixel is 0 or 255)
     * @param int                         $width  Width in pixels (must match line width)
     *
     * @throws RuntimeException If stream write fails
     *
     * @return int Number of bytes written
     */
    public static function packLinesToStream($stream, array $lines, int $width): int
    {
        if (!is_resource($stream)) {
            throw new RuntimeException('Stream must be a valid resource');
        }

        $bytesWritten = 0;
        $bytesPerLine = (int) ceil($width / 8);

        foreach ($lines as $line) {
            $lineData = '';
            $byte     = 0;
            $bitPos   = 7;

            for ($col = 0; $col < $width; $col++) {
                // Pixel value: 255 = black, 0 = white
                // Packed bit: 1 = black, 0 = white
                $pixelBit = ($line[$col] === 255) ? 1 : 0;

                $byte |= ($pixelBit << $bitPos);
                $bitPos--;

                if ($bitPos < 0 || $col === $width - 1) {
                    // Byte is complete or end of line
                    $lineData .= chr($byte);
                    $byte   = 0;
                    $bitPos = 7;
                }
            }

            // Pad line to bytesPerLine if needed
            while (strlen($lineData) < $bytesPerLine) {
                $lineData .= chr(0);
            }

            // Write to stream
            $written = fwrite($stream, $lineData);

            if ($written === false) {
                throw new RuntimeException('Failed to write to stream');
            }

            $bytesWritten += $written;
        }

        return $bytesWritten;
    }

    /**
     * Pack a single pixel line to bytes (streaming helper).
     *
     * This method packs a single line into bytes without accumulating data.
     * Useful for line-by-line streaming.
     *
     * @param array<int, int> $line  Pixel line (each pixel is 0 or 255)
     * @param int             $width Width in pixels
     *
     * @return string Packed binary data for this line
     */
    public static function packSingleLine(array $line, int $width): string
    {
        $bytesPerLine = (int) ceil($width / 8);
        $lineData     = '';
        $byte         = 0;
        $bitPos       = 7;

        for ($col = 0; $col < $width; $col++) {
            // Pixel value: 255 = black, 0 = white
            // Packed bit: 1 = black, 0 = white
            $pixelBit = ($line[$col] === 255) ? 1 : 0;

            $byte |= ($pixelBit << $bitPos);
            $bitPos--;

            if ($bitPos < 0 || $col === $width - 1) {
                // Byte is complete or end of line
                $lineData .= chr($byte);
                $byte   = 0;
                $bitPos = 7;
            }
        }

        // Pad line to bytesPerLine if needed
        while (strlen($lineData) < $bytesPerLine) {
            $lineData .= chr(0);
        }

        return $lineData;
    }
}
