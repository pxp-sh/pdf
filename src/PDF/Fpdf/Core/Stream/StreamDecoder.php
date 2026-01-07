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
namespace PXP\PDF\Fpdf\Core\Stream;

use function chr;
use function function_exists;
use function gzuncompress;
use function hex2bin;
use function ord;
use function pack;
use function preg_replace;
use function rtrim;
use function str_pad;
use function str_repeat;
use function strlen;
use function substr;
use PXP\PDF\CCITTFax\Decoder\CCITT3Decoder;
use PXP\PDF\CCITTFax\Decoder\CCITT3MixedDecoder;
use PXP\PDF\CCITTFax\Decoder\CCITT4Decoder;
use PXP\PDF\CCITTFax\Model\Params;
use PXP\PDF\Fpdf\Core\Object\Base\PDFArray;
use PXP\PDF\Fpdf\Core\Object\Base\PDFBoolean;
use PXP\PDF\Fpdf\Core\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Core\Object\Base\PDFName;
use PXP\PDF\Fpdf\Core\Object\Base\PDFNumber;
use PXP\PDF\Fpdf\Exceptions\Exception\FpdfException;
use Throwable;

/**
 * Handles decoding of PDF stream data using various filters.
 */
final class StreamDecoder
{
    /**
     * Decode stream data based on filters in the dictionary.
     */
    public function decode(string $data, PDFDictionary $filters): string
    {
        $filter      = $filters->getEntry('/Filter');
        $decodeParms = $filters->getEntry('/DecodeParms');

        // Handle single filter
        if ($filter instanceof PDFName) {
            return $this->decodeWithFilter($data, $filter->getName(), $decodeParms);
        }

        // Handle array of filters
        if ($filter instanceof PDFArray) {
            $result      = $data;
            $filtersList = $filter->getAll();
            $paramsList  = $decodeParms instanceof PDFArray ? $decodeParms->getAll() : [];

            foreach ($filtersList as $index => $filterItem) {
                $filterName = $filterItem instanceof PDFName ? $filterItem->getName() : (string) $filterItem;
                $params     = $paramsList[$index] ?? null;
                $result     = $this->decodeWithFilter($result, $filterName, $params);
            }

            return $result;
        }

        // No filter, return as-is
        return $data;
    }

    /**
     * Decode FlateDecode (zlib/deflate).
     */
    public function decodeFlate(string $data): string
    {
        if (!function_exists('gzuncompress')) {
            throw new FpdfException('zlib extension is required for FlateDecode');
        }

        $result = @gzuncompress($data);

        if ($result === false) {
            throw new FpdfException('Failed to decode FlateDecode stream');
        }

        return $result;
    }

    /**
     * Decode DCTDecode (JPEG).
     * DCTDecode is pass-through as JPEG is already compressed.
     */
    public function decodeDCT(string $data): string
    {
        return $data;
    }

    /**
     * Decode LZWDecode.
     * Note: PHP doesn't have built-in LZW, this is a placeholder.
     */
    public function decodeLZW(string $data): string
    {
        throw new FpdfException('LZWDecode is not yet implemented');
    }

    /**
     * Decode RunLengthDecode.
     */
    public function decodeRunLength(string $data): string
    {
        $result = '';
        $length = strlen($data);
        $i      = 0;

        while ($i < $length) {
            $byte = ord($data[$i]);

            if ($byte === 128) {
                // End of data marker
                break;
            }

            if ($byte < 128) {
                // Literal run: copy next (byte + 1) bytes
                $count = $byte + 1;
                $result .= substr($data, $i + 1, $count);
                $i += $count + 1;
            } else {
                // Repeated run: repeat next byte (257 - byte) times
                $count      = 257 - $byte;
                $repeatByte = $i + 1 < $length ? $data[$i + 1] : "\0";
                $result .= str_repeat($repeatByte, $count);
                $i += 2;
            }
        }

        return $result;
    }

    /**
     * Decode ASCIIHexDecode.
     */
    public function decodeASCIIHex(string $data): string
    {
        // Remove whitespace and > marker
        $hex = preg_replace('/\s+/', '', $data);
        $hex = rtrim($hex, '>');

        if (strlen($hex) % 2 !== 0) {
            // Pad with 0 if odd length
            $hex .= '0';
        }

        return hex2bin($hex);
    }

    /**
     * Decode ASCII85Decode.
     */
    public function decodeASCII85(string $data): string
    {
        // Remove whitespace and ~> marker
        $ascii85 = preg_replace('/\s+/', '', $data);
        $ascii85 = rtrim($ascii85, '~>');

        $result = '';
        $length = strlen($ascii85);
        $i      = 0;

        while ($i < $length) {
            // Check for z shortcut (4 zeros)
            if ($ascii85[$i] === 'z') {
                $result .= "\0\0\0\0";
                $i++;

                continue;
            }

            // Read 5 characters
            $chunk = substr($ascii85, $i, 5);

            if (strlen($chunk) < 5) {
                // Pad with 'u' if needed
                $chunk = str_pad($chunk, 5, 'u');
            }

            // Convert from base 85
            $value = 0;

            for ($j = 0; $j < 5; $j++) {
                $char  = ord($chunk[$j]) - 33;
                $value = $value * 85 + $char;
            }

            // Convert to 4 bytes
            $bytes = pack('N', $value);

            if ($i + 5 > $length) {
                // Last chunk, remove padding
                $bytes = substr($bytes, 0, $length - $i - 1);
            }
            $result .= $bytes;

            $i += 5;
        }

        return $result;
    }

    /**
     * Decode CCITTFaxDecode.
     *
     * @param null|float|int|PDFDictionary|PDFName|string $params
     */
    public function decodeCCITTFax(string $data, mixed $params): string
    {
        // Extract parameters from DecodeParms dictionary
        $paramArray = [];

        if ($params instanceof PDFDictionary) {
            // Extract all standard CCITT parameters
            $entries = [
                'K'                      => '/K',
                'EndOfLine'              => '/EndOfLine',
                'EncodedByteAlign'       => '/EncodedByteAlign',
                'Columns'                => '/Columns',
                'Rows'                   => '/Rows',
                'EndOfBlock'             => '/EndOfBlock',
                'BlackIs1'               => '/BlackIs1',
                'DamagedRowsBeforeError' => '/DamagedRowsBeforeError',
            ];

            foreach ($entries as $key => $pdfKey) {
                $entry = $params->getEntry($pdfKey);

                if ($entry !== null) {
                    if ($entry instanceof PDFNumber) {
                        $paramArray[$key] = $entry->getValue();
                    } elseif ($entry instanceof PDFBoolean) {
                        $paramArray[$key] = $entry->getValue();
                    } else {
                        $paramArray[$key] = $entry;
                    }
                }
            }
        }

        // Create params object with defaults
        $ccittParams = Params::fromArray($paramArray);

        try {
            // Select decoder based on K parameter
            $decoder = $this->selectCCITTDecoder($ccittParams, $data);
            $lines   = $decoder->decode();

            // Convert the 2D array of pixel values to a binary string
            // For now, keep uncompressed format (1 byte per pixel) for compatibility
            // TODO: Consider using BitmapPacker::packLines() for 8x space savings
            $result = '';

            foreach ($lines as $line) {
                foreach ($line as $pixel) {
                    $result .= chr($pixel);
                }
            }

            return $result;
        } catch (Throwable $e) {
            throw new FpdfException('Failed to decode CCITTFax stream: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Decode with a specific filter.
     *
     * @param null|float|int|PDFDictionary|PDFName|string $params
     */
    private function decodeWithFilter(string $data, string $filterName, mixed $params): string
    {
        return match ($filterName) {
            'FlateDecode', '/FlateDecode' => $this->decodeFlate($data),
            'DCTDecode', '/DCTDecode' => $this->decodeDCT($data),
            'LZWDecode', '/LZWDecode' => $this->decodeLZW($data),
            'RunLengthDecode', '/RunLengthDecode' => $this->decodeRunLength($data),
            'ASCIIHexDecode', '/ASCIIHexDecode' => $this->decodeASCIIHex($data),
            'ASCII85Decode', '/ASCII85Decode' => $this->decodeASCII85($data),
            'CCITTFaxDecode', '/CCITTFaxDecode' => $this->decodeCCITTFax($data, $params),
            default => throw new FpdfException('Unknown filter: ' . $filterName),
        };
    }

    /**
     * Select appropriate CCITT decoder based on parameters.
     *
     * @return CCITT3Decoder|CCITT3MixedDecoder|CCITT4Decoder
     */
    private function selectCCITTDecoder(Params $params, string $data): object
    {
        if ($params->isGroup4()) {
            // Group 4 (K < 0): Pure 2D encoding
            return new CCITT4Decoder($params->getColumns(), $data, $params->getBlackIs1());
        }

        if ($params->isPure1D()) {
            // Group 3 1D (K = 0): Modified Huffman
            return new CCITT3Decoder($params, $data);
        }

        // Mixed mode (K > 0): Group 3 with 2D extensions
        return new CCITT3MixedDecoder($params, $data);
    }
}
