<?php declare(strict_types=1);

/**
 * Copyright (c) 2025-2026 PXP
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/pxp-sh/pdf
 *
 */

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        symfonyCodeQuality: true,
        naming: true,
        typeDeclarationDocblocks: true,
    )->withPhpSets(
        php84: true,
    );
