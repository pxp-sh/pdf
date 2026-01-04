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
namespace PXP\PDF\Fpdf\Font;

final readonly class FontInfo
{
    public function __construct(
        public string $name,
        public string $type,
        public array $cw,
        public int $i,
        public array $desc = [],
        public ?string $file = null,
        public ?string $enc = null,
        public ?string $diff = null,
        public ?array $uv = null,
        public bool $subsetted = false,
        public ?int $up = null,
        public ?int $ut = null,
        public ?int $originalsize = null,
        public ?int $size1 = null,
        public ?int $size2 = null,
    ) {
    }
}
