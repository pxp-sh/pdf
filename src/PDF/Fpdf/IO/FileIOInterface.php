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

/**
 * Convenience interface combining all file I/O operations.
 * Extends FileReaderInterface, FileWriterInterface, and StreamFactoryInterface.
 * Use this when a class needs all file operations.
 */
interface FileIOInterface extends FileReaderInterface, FileWriterInterface, StreamFactoryInterface
{
}
