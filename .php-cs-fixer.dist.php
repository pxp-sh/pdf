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

use Ergebnis\License;
use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$license = License\Type\MIT::text(
    __DIR__ . '/LICENSE',
    License\Range::since(
        License\Year::fromString('2025'),
        new DateTimeZone('UTC')
    ),
    License\Holder::fromString('PXP'),
    License\Url::fromString('https://github.com/pxp-sh/pdf')
);

$license->save();

$finder = Finder::create()
    ->in(__DIR__)
    ->append(glob(__DIR__ . '/*.php'))
    ->exclude('vendor')
    ->exclude('var')
    ->append(glob(__DIR__ . '/.*.php'));

return (new Config())
    ->setFinder($finder)
    ->setRules([
        'header_comment' => [
            'comment_type' => 'PHPDoc',
            'header' => $license->header(),
            'location' => 'after_declare_strict',
            'separate' => 'both',
        ],
    ]);
