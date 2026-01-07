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

use PXP\PDF\Fpdf\Exceptions\Exception\FpdfException;

/**
 * Interface for file reading operations.
 * Follows Interface Segregation Principle - clients only depend on reading operations.
 */
interface FileReaderInterface
{
    /**
     * Read entire file contents.
     *
     * @param string $path File path to read
     *
     * @throws FpdfException If file cannot be read
     *
     * @return string File contents
     */
    public function readFile(string $path): string;

    /**
     * Read a chunk of file contents.
     *
     * @param string $path   File path to read
     * @param int    $length Number of bytes to read
     * @param int    $offset Byte offset to start reading from (default: 0)
     *
     * @throws FpdfException If file cannot be read
     *
     * @return string File chunk contents
     */
    public function readFileChunk(string $path, int $length, int $offset = 0): string;

    /**
     * Open a read stream for the file.
     *
     * @param string $path File path to open
     *
     * @throws FpdfException If file cannot be opened
     *
     * @return resource Stream resource
     */
    public function openReadStream(string $path);
}
