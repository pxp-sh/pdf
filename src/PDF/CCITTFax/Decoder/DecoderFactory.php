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
namespace PXP\PDF\CCITTFax\Decoder;

use PXP\PDF\CCITTFax\Model\Params;
use PXP\PDF\CCITTFax\Interface\StreamDecoderInterface;
use RuntimeException;

final class DecoderFactory
{
    public static function createForParams(Params $params, $data): StreamDecoderInterface
    {
        if ($params->getK() === 0) {
            return new CCITT3Decoder($params, $data);
        }

        if ($params->getK() > 0) {
            return new CCITT3MixedDecoder($params, $data);
        }

        if ($params->getK() < 0) {
            // Group 4
            return new CCITT4Decoder($params->getColumns(), $data, $params->getBlackIs1());
        }

        throw new RuntimeException('Invalid K parameter for CCITT decoder');
    }
}