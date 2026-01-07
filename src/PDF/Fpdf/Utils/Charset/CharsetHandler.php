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
namespace PXP\PDF\Fpdf\Utils\Charset;

use function chr;
use function function_exists;
use function iconv;
use function mb_check_encoding;
use function ord;
use function preg_match;
use function str_starts_with;
use function strlen;
use function substr;
use PXP\PDF\Fpdf\Exceptions\Exception\FpdfException;

/**
 * Handles text encoding/decoding for PDF strings.
 */
final class CharsetHandler
{
    /**
     * Encode text to PDF string format.
     */
    public function encodeToPDF(string $text, string $charset = 'UTF-8'): string
    {
        return match ($charset) {
            'UTF-8' => $this->encodeUTF8($text),
            'UTF-16BE', 'UTF-16' => $this->encodeUTF16BE($text),
            'WinAnsiEncoding', 'Windows-1252' => $this->encodeWinAnsi($text),
            'MacRomanEncoding', 'MacRoman' => $this->encodeMacRoman($text),
            'ISO-8859-1', 'Latin1' => $this->encodeISO8859_1($text),
            'PDFDocEncoding' => $this->encodePDFDoc($text),
            default          => throw new FpdfException('Unsupported charset: ' . $charset),
        };
    }

    /**
     * Decode text from PDF string format.
     */
    public function decodeFromPDF(string $pdfString, string $charset = 'UTF-8'): string
    {
        return match ($charset) {
            'UTF-8' => $this->decodeUTF8($pdfString),
            'UTF-16BE', 'UTF-16' => $this->decodeUTF16BE($pdfString),
            'WinAnsiEncoding', 'Windows-1252' => $this->decodeWinAnsi($pdfString),
            'MacRomanEncoding', 'MacRoman' => $this->decodeMacRoman($pdfString),
            'ISO-8859-1', 'Latin1' => $this->decodeISO8859_1($pdfString),
            'PDFDocEncoding' => $this->decodePDFDoc($pdfString),
            default          => throw new FpdfException('Unsupported charset: ' . $charset),
        };
    }

    /**
     * Detect charset from text content.
     */
    public function detectCharset(string $text): string
    {
        // Check for UTF-16 BOM
        if (str_starts_with($text, "\xFE\xFF")) {
            return 'UTF-16BE';
        }

        // Try to detect UTF-8
        if (mb_check_encoding($text, 'UTF-8')) {
            return 'UTF-8';
        }

        // Default to ISO-8859-1
        return 'ISO-8859-1';
    }

    /**
     * Convert text from one charset to another.
     */
    public function convertCharset(string $text, string $from, string $to): string
    {
        if ($from === $to) {
            return $text;
        }

        if (function_exists('iconv')) {
            $result = @iconv($from, $to . '//IGNORE', $text);

            if ($result !== false) {
                return $result;
            }
        }

        // Fallback: decode from source, encode to target
        $decoded = $this->decodeFromPDF($text, $from);

        return $this->encodeToPDF($decoded, $to);
    }

    /**
     * Encode UTF-8 to PDF string (may need UTF-16 for non-ASCII).
     */
    private function encodeUTF8(string $text): string
    {
        // If all ASCII, return as-is
        if (preg_match('/^[\x00-\x7F]*$/', $text)) {
            return $text;
        }

        // Otherwise encode as UTF-16BE
        return $this->encodeUTF16BE($text);
    }

    /**
     * Decode UTF-8 from PDF string.
     */
    private function decodeUTF8(string $text): string
    {
        // Check if it's UTF-16BE
        if (str_starts_with($text, "\xFE\xFF")) {
            return $this->decodeUTF16BE($text);
        }

        return $text;
    }

    /**
     * Encode to UTF-16BE with BOM.
     */
    private function encodeUTF16BE(string $text): string
    {
        if (function_exists('iconv')) {
            return "\xFE\xFF" . iconv('UTF-8', 'UTF-16BE', $text);
        }

        // Manual UTF-8 to UTF-16BE conversion
        $result = "\xFE\xFF";
        $length = strlen($text);
        $i      = 0;

        while ($i < $length) {
            $byte1 = ord($text[$i++]);

            if ($byte1 < 0x80) {
                $result .= "\x00" . chr($byte1);
            } elseif (($byte1 & 0xE0) === 0xC0) {
                $byte2     = ord($text[$i++]);
                $codePoint = (($byte1 & 0x1F) << 6) | ($byte2 & 0x3F);
                $result .= chr($codePoint >> 8) . chr($codePoint & 0xFF);
            } elseif (($byte1 & 0xF0) === 0xE0) {
                $byte2     = ord($text[$i++]);
                $byte3     = ord($text[$i++]);
                $codePoint = (($byte1 & 0x0F) << 12) | (($byte2 & 0x3F) << 6) | ($byte3 & 0x3F);
                $result .= chr($codePoint >> 8) . chr($codePoint & 0xFF);
            } else {
                // 4-byte UTF-8 (surrogate pairs)
                $byte2     = ord($text[$i++]);
                $byte3     = ord($text[$i++]);
                $byte4     = ord($text[$i++]);
                $codePoint = (($byte1 & 0x07) << 18) | (($byte2 & 0x3F) << 12) | (($byte3 & 0x3F) << 6) | ($byte4 & 0x3F);
                $codePoint -= 0x10000;
                $high = 0xD800 + ($codePoint >> 10);
                $low  = 0xDC00 + ($codePoint & 0x3FF);
                $result .= chr($high >> 8) . chr($high & 0xFF);
                $result .= chr($low >> 8) . chr($low & 0xFF);
            }
        }

        return $result;
    }

    /**
     * Decode UTF-16BE with BOM.
     */
    private function decodeUTF16BE(string $text): string
    {
        if (str_starts_with($text, "\xFE\xFF")) {
            $text = substr($text, 2);
        }

        if (function_exists('iconv')) {
            return iconv('UTF-16BE', 'UTF-8', $text);
        }

        // Manual UTF-16BE to UTF-8 conversion
        $result = '';
        $length = strlen($text);

        for ($i = 0; $i < $length; $i += 2) {
            $high      = ord($text[$i]);
            $low       = ord($text[$i + 1] ?? "\0");
            $codePoint = ($high << 8) | $low;

            if ($codePoint < 0x80) {
                $result .= chr($codePoint);
            } elseif ($codePoint < 0x800) {
                $result .= chr(0xC0 | ($codePoint >> 6));
                $result .= chr(0x80 | ($codePoint & 0x3F));
            } elseif ($codePoint < 0xD800 || $codePoint >= 0xE000) {
                $result .= chr(0xE0 | ($codePoint >> 12));
                $result .= chr(0x80 | (($codePoint >> 6) & 0x3F));
                $result .= chr(0x80 | ($codePoint & 0x3F));
            } else {
                // Surrogate pair
                $highSurrogate = $codePoint;
                $i += 2;
                $lowHigh      = ord($text[$i] ?? "\0");
                $lowLow       = ord($text[$i + 1] ?? "\0");
                $lowSurrogate = ($lowHigh << 8) | $lowLow;
                $codePoint    = 0x10000 + (($highSurrogate & 0x3FF) << 10) + ($lowSurrogate & 0x3FF);

                $result .= chr(0xF0 | ($codePoint >> 18));
                $result .= chr(0x80 | (($codePoint >> 12) & 0x3F));
                $result .= chr(0x80 | (($codePoint >> 6) & 0x3F));
                $result .= chr(0x80 | ($codePoint & 0x3F));
            }
        }

        return $result;
    }

    /**
     * Encode to Windows-1252.
     */
    private function encodeWinAnsi(string $text): string
    {
        if (function_exists('iconv')) {
            return iconv('UTF-8', 'Windows-1252//IGNORE', $text);
        }

        return $text; // Fallback
    }

    /**
     * Decode from Windows-1252.
     */
    private function decodeWinAnsi(string $text): string
    {
        if (function_exists('iconv')) {
            return iconv('Windows-1252', 'UTF-8//IGNORE', $text);
        }

        return $text; // Fallback
    }

    /**
     * Encode to MacRoman.
     */
    private function encodeMacRoman(string $text): string
    {
        if (function_exists('iconv')) {
            return iconv('UTF-8', 'MacRoman//IGNORE', $text);
        }

        return $text; // Fallback
    }

    /**
     * Decode from MacRoman.
     */
    private function decodeMacRoman(string $text): string
    {
        if (function_exists('iconv')) {
            return iconv('MacRoman', 'UTF-8//IGNORE', $text);
        }

        return $text; // Fallback
    }

    /**
     * Encode to ISO-8859-1.
     */
    private function encodeISO8859_1(string $text): string
    {
        if (function_exists('iconv')) {
            return iconv('UTF-8', 'ISO-8859-1//IGNORE', $text);
        }

        return $text; // Fallback
    }

    /**
     * Decode from ISO-8859-1.
     */
    private function decodeISO8859_1(string $text): string
    {
        if (function_exists('iconv')) {
            return iconv('ISO-8859-1', 'UTF-8//IGNORE', $text);
        }

        return $text; // Fallback
    }

    /**
     * Encode to PDFDocEncoding.
     * PDFDocEncoding is similar to ISO-8859-1 with some differences.
     */
    private function encodePDFDoc(string $text): string
    {
        // PDFDocEncoding is mostly ISO-8859-1 compatible
        return $this->encodeISO8859_1($text);
    }

    /**
     * Decode from PDFDocEncoding.
     */
    private function decodePDFDoc(string $text): string
    {
        // PDFDocEncoding is mostly ISO-8859-1 compatible
        return $this->decodeISO8859_1($text);
    }
}
