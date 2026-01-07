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
namespace PXP\PDF\CCITTFax\Constants;

use function count;
use function sprintf;
use RuntimeException;
use PXP\PDF\CCITTFax\Model\Mode;
use PXP\PDF\CCITTFax\Model\ModeCode;

class Modes
{
    /** @var ModeCode[] */
    private array $modes;

    public function __construct()
    {
        $this->modes = $this->getModes();
    }

    public function getMode(int $b8): ModeCode
    {
        foreach ($this->modes as $mode) {
            if ($mode->matches($b8)) {
                return $mode;
            }
        }

        throw new RuntimeException(sprintf(
            'bad start: no mode found for byte 0x%02X (binary: %08b)',
            $b8,
            $b8,
        ));
    }

    /**
     * @return ModeCode[]
     */
    private function getModes(): array
    {
        $modeCodes = [
            0x1,
            4,
            1,
            0x1,
            3,
            2,
            0x1,
            1,
            3, // 1
            0x03,
            3,
            4, // 011
            0x03,
            6,
            5, // 0000 11
            0x03,
            7,
            6, // 0000 011
            0x2,
            3,
            7, // 010
            0x02,
            6,
            8, // 0000 10
            0x02,
            7,
            9, // 0000 010
            0x01,
            7,
            10, // 0000 010
        ];

        $modes = [];
        $count = count($modeCodes) / 3;

        for ($i = 0; $i < $count; $i++) {
            $bitsUsed = $modeCodes[$i * 3 + 1];
            $value    = $modeCodes[$i * 3] << (8 - $bitsUsed);
            $mask     = 0xFF << (8 - $bitsUsed);
            $type     = Mode::from($modeCodes[$i * 3 + 2]);

            $modes[] = new ModeCode($bitsUsed, $mask, $value, $type);
        }

        return $modes;
    }
}
