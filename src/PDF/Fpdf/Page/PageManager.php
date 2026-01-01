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

namespace PXP\PDF\Fpdf\Page;

use PXP\PDF\Fpdf\Enum\PageOrientation;
use PXP\PDF\Fpdf\Event\NullDispatcher;
use PXP\PDF\Fpdf\IO\FileIOInterface;
use PXP\PDF\Fpdf\Log\NullLogger;
use PXP\PDF\Fpdf\ValueObject\PageSize;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

final class PageManager
{
    private int $currentPage = 0;
    private array $pages = [];
    private array $pageInfo = [];
    private ?string $tempDir = null;
    private array $tempFiles = [];

    public function __construct(
        private FileIOInterface $fileIO,
        ?LoggerInterface $logger = null,
        ?EventDispatcherInterface $dispatcher = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->dispatcher = $dispatcher ?? new NullDispatcher();

        // Create temporary directory for page storage
        $this->tempDir = sys_get_temp_dir() . '/pxp_pdf_' . uniqid('', true);
        $this->logger->debug('Creating temporary directory for page storage', [
            'temp_dir' => $this->tempDir,
        ]);

        if (!is_dir($this->tempDir)) {
            if (!mkdir($this->tempDir, 0777, true) && !is_dir($this->tempDir)) {
                $this->logger->error('Failed to create temporary directory for PDF pages', [
                    'temp_dir' => $this->tempDir,
                ]);
                throw new \RuntimeException('Failed to create temporary directory for PDF pages: ' . $this->tempDir);
            }
        }
    }

    private LoggerInterface $logger;
    private EventDispatcherInterface $dispatcher;

    public function addPage(): int
    {
        // Finalize previous page if exists
        if ($this->currentPage > 0 && isset($this->pages[$this->currentPage])) {
            $this->finalizePage($this->currentPage);
        }

        $this->currentPage++;
        $this->pages[$this->currentPage] = '';
        $this->pageInfo[$this->currentPage] = [];

        $this->logger->debug('Page added', [
            'page_number' => $this->currentPage,
        ]);

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

        $this->logger->debug('Page content appended', [
            'page_number' => $page,
            'content_length' => strlen($content),
            'total_page_length' => strlen($this->pages[$page]),
        ]);
    }

    public function getPageContent(int $page): string
    {
        // If page is finalized (written to temp file), read from file
        if (isset($this->tempFiles[$page]) && file_exists($this->tempFiles[$page])) {
            $this->logger->debug('Page content retrieved from file', [
                'page_number' => $page,
                'file_path' => $this->tempFiles[$page],
                'source' => 'file',
            ]);
            try {
                return $this->fileIO->readFile($this->tempFiles[$page]);
            } catch (\PXP\PDF\Fpdf\Exception\FpdfException $e) {
                $this->logger->warning('Failed to read page content from file', [
                    'page_number' => $page,
                    'file_path' => $this->tempFiles[$page],
                    'error' => $e->getMessage(),
                ]);
                return '';
            }
        }

        // Otherwise, return from memory buffer
        $content = $this->pages[$page] ?? '';
        $this->logger->debug('Page content retrieved from memory', [
            'page_number' => $page,
            'content_length' => strlen($content),
            'source' => 'memory',
        ]);
        return $content;
    }

    public function getAllPages(): array
    {
        // Finalize current page if it hasn't been finalized
        if ($this->currentPage > 0 && isset($this->pages[$this->currentPage])) {
            $this->finalizePage($this->currentPage);
        }

        // Read all pages from temp files
        $allPages = [];
        for ($i = 1; $i <= $this->currentPage; $i++) {
            $allPages[$i] = $this->getPageContent($i);
        }

        return $allPages;
    }

    public function setPageInfo(int $page, string $key, mixed $value): void
    {
        if (!isset($this->pageInfo[$page])) {
            $this->pageInfo[$page] = [];
        }

        $this->pageInfo[$page][$key] = $value;

        $this->logger->debug('Page info updated', [
            'page_number' => $page,
            'key' => $key,
            'value_type' => gettype($value),
        ]);
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
        // If page is in temp file, read, replace, and write back
        if (isset($this->tempFiles[$page]) && file_exists($this->tempFiles[$page])) {
            try {
                $content = $this->fileIO->readFile($this->tempFiles[$page]);
                $content = str_replace($search, $replace, $content);
                $this->fileIO->writeFile($this->tempFiles[$page], $content);
            } catch (\PXP\PDF\Fpdf\Exception\FpdfException $e) {
                // Ignore errors, fall through to memory check
            }
        } elseif (isset($this->pages[$page])) {
            // If still in memory, replace in memory
            $this->pages[$page] = str_replace($search, $replace, $this->pages[$page]);
        }
    }

    /**
     * Finalize a page by writing it to a temporary file and clearing it from memory
     */
    private function finalizePage(int $page): void
    {
        if (!isset($this->pages[$page]) || $this->pages[$page] === '') {
            return;
        }

        $contentLength = strlen($this->pages[$page]);
        $tempFile = $this->tempDir . '/page_' . $page . '.tmp';

        $this->logger->debug('Finalizing page', [
            'page_number' => $page,
            'content_length' => $contentLength,
            'temp_file' => $tempFile,
        ]);

        // Write page content to temp file
        $this->fileIO->writeFile($tempFile, $this->pages[$page]);
        $this->tempFiles[$page] = $tempFile;

        // Clear from memory to free up space
        unset($this->pages[$page]);

        $this->logger->debug('Page finalized and written to temp file', [
            'page_number' => $page,
            'temp_file' => $tempFile,
            'content_length' => $contentLength,
        ]);

        // Force garbage collection hint
        if ($page % 10 === 0) {
            gc_collect_cycles();
            $this->logger->debug('Garbage collection triggered', [
                'page_number' => $page,
            ]);
        }
    }

    /**
     * Finalize the current page (called when document is closed)
     */
    public function finalizeCurrentPage(): void
    {
        if ($this->currentPage > 0 && isset($this->pages[$this->currentPage])) {
            $this->finalizePage($this->currentPage);
        }
    }

    /**
     * Clean up temporary files
     */
    public function cleanup(): void
    {
        $fileCount = count($this->tempFiles);
        $this->logger->debug('Cleaning up temporary files', [
            'temp_dir' => $this->tempDir,
            'file_count' => $fileCount,
        ]);

        // Remove all temp files
        foreach ($this->tempFiles as $tempFile) {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
                $this->logger->debug('Temporary file deleted', [
                    'file_path' => $tempFile,
                ]);
            }
        }

        // Remove temp directory if it exists
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
            $this->logger->debug('Temporary directory removed', [
                'temp_dir' => $this->tempDir,
            ]);
        }

        $this->tempFiles = [];
        $this->tempDir = null;
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
