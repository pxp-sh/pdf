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
 * Interface for stream creation operations.
 * Follows Interface Segregation Principle - clients only depend on stream creation.
 */
interface StreamFactoryInterface
{
    /**
     * Create a temporary stream.
     *
     * @param string $mode Stream mode (default: 'rb+')
     *
     * @throws FpdfException If stream cannot be created
     *
     * @return resource Stream resource
     */
    public function createTempStream(string $mode = 'rb+');
}
