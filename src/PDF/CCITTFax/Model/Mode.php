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
namespace PXP\PDF\CCITTFax\Model;

enum Mode: int
{
    case Pass         = 1;
    case Horizontal   = 2;
    case VerticalZero = 3;
    case VerticalR1   = 4;
    case VerticalR2   = 5;
    case VerticalR3   = 6;
    case VerticalL1   = 7;
    case VerticalL2   = 8;
    case VerticalL3   = 9;
    case Extension    = 10;
}
