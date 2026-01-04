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
namespace PXP\PDF\Fpdf\Tree;

use function preg_match;

/**
 * Represents the PDF header (version declaration).
 */
final class PDFHeader
{
    /**
     * Parse PDF header from content.
     */
    public static function parse(string $content): ?self
    {
        if (preg_match('/%PDF-(\d\.\d)/', $content, $matches)) {
            return new self($matches[1]);
        }

        return null;
    }

    public function __construct(
        private string $version = '1.3',
    ) {
    }

    public function __toString(): string
    {
        return '%PDF-' . $this->version . "\n";
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function setVersion(string $version): void
    {
        $this->version = $version;
    }
}
