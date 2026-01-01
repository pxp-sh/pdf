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

namespace Test\Helper;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Factory for creating PSR implementations for tests.
 */
final class PsrFactory
{
    /**
     * Create a Monolog logger with debug level enabled.
     */
    public static function createLogger(?string $name = 'test', ?string $logFile = null): LoggerInterface
    {
        $logger = new Logger($name ?? 'test');

        // Add console handler with debug level
        $handler = new StreamHandler('php://stderr', Level::Debug);
        $logger->pushHandler($handler);

        // Optionally add file handler if log file is provided
        if ($logFile !== null) {
            $fileHandler = new StreamHandler($logFile, Level::Debug);
            $logger->pushHandler($fileHandler);
        }

        return $logger;
    }

    /**
     * Create a Symfony ArrayCache for tests.
     */
    public static function createCache(): CacheItemPoolInterface
    {
        return new ArrayAdapter();
    }

    /**
     * Create a Symfony EventDispatcher for tests.
     */
    public static function createEventDispatcher(): EventDispatcherInterface
    {
        return new EventDispatcher();
    }
}
