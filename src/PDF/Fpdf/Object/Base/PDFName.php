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
namespace PXP\PDF\Fpdf\Object\Base;

use function str_replace;
use function str_starts_with;
use function substr;

/**
 * Represents a PDF name object (e.g., /Type, /Page).
 */
final class PDFName extends PDFObject
{
    private readonly string $name;

    public function __construct(string $name)
    {
        // Remove leading slash if present
        if (str_starts_with($name, '/')) {
            $name = substr($name, 1);
        }
        $this->name = $name;
    }

    public function __toString(): string
    {
        // Escape special characters in name
        $escaped = str_replace(
            ['#', '(', ')', '<', '>', '[', ']', '{', '}', '/', '%'],
            ['#23', '#28', '#29', '#3C', '#3E', '#5B', '#5D', '#7B', '#7D', '#2F', '#25'],
            $this->name,
        );

        return '/' . $escaped;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
