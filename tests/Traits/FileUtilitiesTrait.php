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

use function dirname;
use function file_exists;
use function getenv;
use function is_dir;
use function is_file;
use function mkdir;
use function unlink;
use Composer\Autoload\ClassLoader;
use ReflectionClass;

trait FileUtilitiesTrait
{
    public static function getRootDir(): string
    {
        $reflectionClass = new ReflectionClass(
            ClassLoader::class,
        );
        $fileName = $reflectionClass->getFileName();
        $dirname  = dirname($fileName, 3) . '/var/tmp';

        if (!is_dir($dirname)) {
            mkdir($dirname, 0o777, true);
        }

        return $dirname;
    }

    public static function unlink(string $filepath): void
    {
        if (getenv('PERSISTENT_PDF_TEST_FILES') === '1') {
            return;
        }

        if (is_file($filepath) && file_exists($filepath)) {
            @unlink($filepath);
        }
    }
}
