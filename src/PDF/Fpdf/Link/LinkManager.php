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

namespace PXP\PDF\Fpdf\Link;

final class LinkManager
{
    private array $links = [];
    private array $pageLinks = [];

    public function addLink(): int
    {
        $n = count($this->links) + 1;
        $this->links[$n] = [0, 0];

        return $n;
    }

    public function setLink(int $link, float $y = 0, int $page = -1): void
    {
        if ($y < 0) {
            $y = 0;
        }

        if ($page < 0) {
            $page = 0;
        }

        $this->links[$link] = [$page, $y];
    }

    public function addPageLink(int $page, float $x, float $y, float $w, float $h, int|string $link): void
    {
        if (!isset($this->pageLinks[$page])) {
            $this->pageLinks[$page] = [];
        }

        $this->pageLinks[$page][] = [$x, $y, $w, $h, $link];
    }

    public function getLink(int $link): ?array
    {
        return $this->links[$link] ?? null;
    }

    public function getPageLinks(int $page): array
    {
        return $this->pageLinks[$page] ?? [];
    }

    public function getAllLinks(): array
    {
        return $this->links;
    }

    public function getAllPageLinks(): array
    {
        return $this->pageLinks;
    }

    public function clearPageLinks(int $page): void
    {
        unset($this->pageLinks[$page]);
    }
}
