<?php

declare(strict_types=1);

/**
 * Copyright (c) 2025 PXP
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/pxp-sh/pdf
 *
 */

namespace PXP\PDF\Fpdf\Page;

use PXP\PDF\Fpdf\Enum\PageOrientation;
use PXP\PDF\Fpdf\ValueObject\PageSize;

final class PageManager
{
    private int $currentPage = 0;
    private array $pages = [];
    private array $pageInfo = [];

    public function addPage(): int
    {
        $this->currentPage++;
        $this->pages[$this->currentPage] = '';
        $this->pageInfo[$this->currentPage] = [];

        return $this->currentPage;
    }

    public function getCurrentPage(): int
    {
        return $this->currentPage;
    }

    public function appendContent(int $page, string $content): void
    {
        if (!isset($this->pages[$page])) {
            $this->pages[$page] = '';
        }

        $this->pages[$page] .= $content . "\n";
    }

    public function getPageContent(int $page): string
    {
        return $this->pages[$page] ?? '';
    }

    public function getAllPages(): array
    {
        return $this->pages;
    }

    public function setPageInfo(int $page, string $key, mixed $value): void
    {
        if (!isset($this->pageInfo[$page])) {
            $this->pageInfo[$page] = [];
        }

        $this->pageInfo[$page][$key] = $value;
    }

    public function getPageInfo(int $page): array
    {
        return $this->pageInfo[$page] ?? [];
    }

    public function getAllPageInfo(): array
    {
        return $this->pageInfo;
    }

    public function replaceInPage(int $page, string $search, string $replace): void
    {
        if (isset($this->pages[$page])) {
            $this->pages[$page] = str_replace($search, $replace, $this->pages[$page]);
        }
    }
}
