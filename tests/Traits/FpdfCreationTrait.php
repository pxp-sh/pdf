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
namespace Test;

use PXP\PDF\Fpdf\Core\FPDF;
use PXP\PDF\Fpdf\IO\FileIO;
use PXP\PDF\Fpdf\Utils\Enum\PageOrientation;
use PXP\PDF\Fpdf\Utils\Enum\Unit;
use PXP\PDF\Fpdf\Utils\ValueObject\PageSize;

trait FpdfCreationTrait
{
    /**
     * Create an FPDF instance with test PSR implementations.
     */
    public static function createFPDF(
        PageOrientation|string $orientation = 'P',
        string|Unit $unit = 'mm',
        array|PageSize|string $size = 'A4',
    ): FPDF {
        return new FPDF(
            $orientation,
            $unit,
            $size,
            null,
            self::getLogger(),
            self::getCache(),
            self::getEventDispatcher(),
        );
    }

    /**
     * Create a FileIO instance with test logger.
     */
    public static function createFileIO(): FileIO
    {
        return new FileIO(self::getLogger());
    }
}
