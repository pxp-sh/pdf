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
namespace PXP\PDF\Fpdf\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;

/**
 * Null logger implementation that does nothing.
 * Used as default when no logger is provided.
 */
final class NullLogger implements LoggerInterface
{
    use LoggerTrait;

    /**
     * @param mixed        $level
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        // Do nothing - this is a null logger
    }
}
