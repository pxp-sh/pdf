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
namespace PXP\PDF\Fpdf\Core\Object\Base;

use function bin2hex;
use function hex2bin;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function substr;
use PXP\PDF\Fpdf\Utils\Charset\CharsetHandler;

/**
 * Represents a PDF string value.
 * Handles both literal strings (text) and hex strings with charset support.
 */
class PDFString extends PDFObject
{
    private ?CharsetHandler $charsetHandler = null;

    /**
     * Parse from PDF string format.
     */
    public static function fromPDFString(string $pdfString, string $charset = 'UTF-8'): self
    {
        $charsetHandler = new CharsetHandler;

        // Check if hex string
        if (str_starts_with($pdfString, '<') && str_ends_with($pdfString, '>')) {
            $hex     = substr($pdfString, 1, -1);
            $decoded = hex2bin($hex);
            $value   = $charsetHandler->decodeFromPDF($decoded, $charset);

            return new self($value, true, $charset);
        }

        // Literal string
        if (str_starts_with($pdfString, '(') && str_ends_with($pdfString, ')')) {
            $content = substr($pdfString, 1, -1);
            // Unescape
            $unescaped = str_replace(
                ['\\\\', '\\(', '\\)', '\\r', '\\n', '\\t'],
                ['\\', '(', ')', "\r", "\n", "\t"],
                $content,
            );
            $value = $charsetHandler->decodeFromPDF($unescaped, $charset);

            return new self($value, false, $charset);
        }

        // Default: treat as literal
        return new self($pdfString, false, $charset);
    }

    public function __construct(protected string $value, protected bool $isHex = false, protected string $charset = 'UTF-8')
    {
    }

    public function __toString(): string
    {
        return $this->toPDFString();
    }

    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Set the string value with optional charset.
     */
    public function setValue(string $value, string $charset = 'UTF-8'): void
    {
        $this->value   = $value;
        $this->charset = $charset;
    }

    public function isHex(): bool
    {
        return $this->isHex;
    }

    public function getCharset(): string
    {
        return $this->charset;
    }

    public function setCharset(string $charset): void
    {
        $this->charset = $charset;
    }

    /**
     * Convert to PDF string format.
     */
    public function toPDFString(): string
    {
        $charsetHandler = $this->getCharsetHandler();
        $encoded        = $charsetHandler->encodeToPDF($this->value, $this->charset);

        if ($this->isHex) {
            return '<' . bin2hex($encoded) . '>';
        }

        // Escape special characters for literal strings
        $escaped = str_replace(
            ['\\', '(', ')', "\r"],
            ['\\\\', '\\(', '\\)', '\\r'],
            $encoded,
        );

        return '(' . $escaped . ')';
    }

    private function getCharsetHandler(): CharsetHandler
    {
        if (!$this->charsetHandler instanceof CharsetHandler) {
            $this->charsetHandler = new CharsetHandler;
        }

        return $this->charsetHandler;
    }
}
