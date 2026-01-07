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
namespace Test\Traits;

use function is_dir;
use function mkdir;
use Bramus\Monolog\Formatter\ColoredLineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\MemoryPeakUsageProcessor;
use Monolog\Processor\MemoryUsageProcessor;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;

trait PsrServicesTrait
{
    private static ?LoggerInterface $logger                   = null;
    private static ?CacheItemPoolInterface $cacheItemPool     = null;
    private static ?EventDispatcherInterface $eventDispatcher = null;

    /**
     * Get the shared logger instance for tests.
     */
    public static function getLogger(): LoggerInterface
    {
        if (!self::$logger instanceof LoggerInterface) {
            $logger        = new Logger('test');
            $streamHandler = new StreamHandler('php://stdout', Level::Debug);
            $streamHandler->setFormatter(new ColoredLineFormatter);
            $logger->pushHandler($streamHandler);
            $logger->pushProcessor(new MemoryUsageProcessor);
            $logger->pushProcessor(new MemoryPeakUsageProcessor);
            self::$logger = $logger;
        }

        return self::$logger;
    }

    /**
     * Get the shared cache instance for tests.
     * Note: Cache is cleared before each test run to ensure consistent results.
     */
    public static function getCache(): CacheItemPoolInterface
    {
        if (self::$cacheItemPool === null) {
            $cacheDir = self::getRootDir() . '/cache';

            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0o777, true);
            }
            self::$cacheItemPool = new FilesystemAdapter('', 0, $cacheDir);
            // Clear cache to ensure consistent test results
            self::$cacheItemPool->clear();
        }

        return self::$cacheItemPool;
    }

    /**
     * Get the shared event dispatcher instance for tests.
     */
    public static function getEventDispatcher(): EventDispatcherInterface
    {
        if (!self::$eventDispatcher instanceof EventDispatcherInterface) {
            self::$eventDispatcher = new EventDispatcher;
        }

        return self::$eventDispatcher;
    }
}
