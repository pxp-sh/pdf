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
namespace PXP\PDF\Fpdf\Image\Parser;

interface ImageParserInterface
{
    /**
     * Parse an image file and return image information.
     *
     * @return array{w: int, h: int, cs: string, bpc: int, f?: string, data: string, dp?: string, pal?: string, trns?: array<int>, smask?: string}
     */
    public function parse(string $file): array;

    /**
     * Check if this parser supports the given file type.
     */
    public function supports(string $type): bool;
}
