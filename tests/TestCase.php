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
