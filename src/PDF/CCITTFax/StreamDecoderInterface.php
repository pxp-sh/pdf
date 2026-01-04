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
namespace PXP\PDF\CCITTFax;

use RuntimeException;

/**
 * Interface for streaming CCITT fax decoders.
 *
 * Streaming decoders write decoded bitmap data directly to a stream resource
 * instead of accumulating all data in memory. This prevents memory overflow
 * for large images.
 */
interface StreamDecoderInterface
{
    /**
     * Decode CCITT fax data and write to a stream.
     *
     * @param resource $outputStream Stream to write decoded bitmap data to
     *
     * @throws RuntimeException on decoding error
     *
     * @return int Number of bytes written to output stream
     */
    public function decodeToStream($outputStream): int;

    /**
     * Get the width of the decoded image in pixels.
     */
    public function getWidth(): int;

    /**
     * Get the height of the decoded image in lines.
     * Returns 0 if height is not known beforehand.
     */
    public function getHeight(): int;
}
