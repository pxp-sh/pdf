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
namespace PXP\PDF\Fpdf\Buffer;

use function strlen;

final class Buffer
{
    private string $content = '';

    public function append(string $line): void
    {
        $this->content .= $line . "\n";
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getLength(): int
    {
        return strlen($this->content);
    }

    public function clear(): void
    {
        $this->content = '';
    }
}
