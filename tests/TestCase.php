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

use Test\Traits\FileUtilitiesTrait;
use Test\Traits\FpdfCreationTrait;
use Test\Traits\GeneralUtilitiesTrait;
use Test\Traits\ImageComparisonTrait;
use Test\Traits\PdfAssertionsTrait;
use Test\Traits\PdfToImageTrait;
use Test\Traits\PsrServicesTrait;

class TestCase extends \PHPUnit\Framework\TestCase
{
    use FileUtilitiesTrait;
    use FpdfCreationTrait;
    use GeneralUtilitiesTrait;
    use ImageComparisonTrait;
    use PdfAssertionsTrait;
    use PdfToImageTrait;
    use PsrServicesTrait;
}
