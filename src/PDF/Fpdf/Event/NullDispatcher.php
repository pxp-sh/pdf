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

namespace PXP\PDF\Fpdf\Event;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Null event dispatcher implementation that does nothing.
 * Used as default when no dispatcher is provided.
 */
final class NullDispatcher implements EventDispatcherInterface
{
    public function dispatch(object $event): object
    {
        // Do nothing - just return the event as-is
        return $event;
    }
}
