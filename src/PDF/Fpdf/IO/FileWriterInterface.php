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
namespace PXP\PDF\Fpdf\IO;

use PXP\PDF\Fpdf\Exception\FpdfException;

/**
 * Interface for file writing operations.
 * Follows Interface Segregation Principle - clients only depend on writing operations.
 */
interface FileWriterInterface
{
    /**
     * Write entire file contents.
     *
     * @param string $path    File path to write
     * @param string $content Content to write
     *
     * @throws FpdfException If file cannot be written
     */
    public function writeFile(string $path, string $content): void;

    /**
     * Write a chunk of content to a file at a specific offset.
     *
     * @param string $path    File path to write
     * @param string $content Content chunk to write
     * @param int    $offset  Byte offset to start writing at (default: 0)
     *
     * @throws FpdfException If file cannot be written
     */
    public function writeFileChunk(string $path, string $content, int $offset = 0): void;

    /**
     * Open a write stream for the file.
     *
     * @param string $path File path to open
     *
     * @throws FpdfException If file cannot be opened
     *
     * @return resource Stream resource
     */
    public function openWriteStream(string $path);
}
