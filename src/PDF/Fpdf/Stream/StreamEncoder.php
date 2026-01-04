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
namespace PXP\PDF\Fpdf\Stream;

use function chr;
use function function_exists;
use function gzcompress;
use function ltrim;
use function strlen;
use function substr;
use PXP\PDF\Fpdf\Exception\FpdfException;

/**
 * Handles encoding of PDF stream data using various filters.
 */
final class StreamEncoder
{
    /**
     * Encode data with specified filters.
     *
     * @param array<string> $filters
     */
    public function encode(string $data, array $filters): string
    {
        $result = $data;

        foreach ($filters as $filter) {
            $filterName = ltrim($filter, '/');
            $result     = $this->encodeWithFilter($result, $filterName);
        }

        return $result;
    }

    /**
     * Encode with FlateDecode (zlib/deflate).
     */
    public function encodeFlate(string $data): string
    {
        if (!function_exists('gzcompress')) {
            throw new FpdfException('zlib extension is required for FlateDecode');
        }

        $result = @gzcompress($data);

        if ($result === false) {
            throw new FpdfException('Failed to encode FlateDecode stream');
        }

        return $result;
    }

    /**
     * Encode with DCTDecode (JPEG).
     * Note: This typically requires external JPEG encoding.
     */
    public function encodeDCT(string $data, array $params = []): string
    {
        // DCTDecode is typically used for already-compressed JPEG data
        // This is a placeholder - actual JPEG encoding would go here
        return $data;
    }

    /**
     * Encode with LZWDecode.
     * Note: PHP doesn't have built-in LZW, this is a placeholder.
     */
    public function encodeLZW(string $data): string
    {
        throw new FpdfException('LZWDecode encoding is not yet implemented');
    }

    /**
     * Encode with RunLengthDecode.
     */
    public function encodeRunLength(string $data): string
    {
        $result = '';
        $length = strlen($data);
        $i      = 0;

        while ($i < $length) {
            $byte  = $data[$i];
            $count = 1;

            // Check for repeated bytes
            while ($i + $count < $length && $data[$i + $count] === $byte && $count < 128) {
                $count++;
            }

            if ($count >= 3) {
                // Use repeated run: (257 - count) followed by byte
                $result .= chr(257 - $count) . $byte;
                $i += $count;
            } else {
                // Use literal run
                $literalStart = $i;
                $literalCount = 0;

                while ($i < $length && $literalCount < 128) {
                    $currentByte = $data[$i];
                    $repeatCount = 1;

                    // Check if next bytes repeat
                    while ($i + $repeatCount < $length && $data[$i + $repeatCount] === $currentByte && $repeatCount < 128) {
                        $repeatCount++;
                    }

                    if ($repeatCount >= 3) {
                        break;
                    }

                    $literalCount++;
                    $i++;
                }

                $result .= chr($literalCount - 1) . substr($data, $literalStart, $literalCount);
            }
        }

        // End marker
        $result .= "\x80";

        return $result;
    }

    /**
     * Encode with a specific filter.
     */
    private function encodeWithFilter(string $data, string $filterName): string
    {
        return match ($filterName) {
            'FlateDecode'     => $this->encodeFlate($data),
            'DCTDecode'       => $this->encodeDCT($data),
            'LZWDecode'       => $this->encodeLZW($data),
            'RunLengthDecode' => $this->encodeRunLength($data),
            'CCITTFaxDecode'  => $data, // Pass through - already encoded
            default           => throw new FpdfException('Unknown filter: ' . $filterName),
        };
    }
}
