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
use function usort;
use PXP\PDF\CCITTFax\Model\HorizontalCode;
use RuntimeException;

class Codes
{
    private const COLOR_BLACK = 0;
    private const COLOR_WHITE = 255;
    private const COLOR_BOTH  = 127;

    /** @var HorizontalCode[] */
    private array $whiteCodes;

    /** @var HorizontalCode[] */
    private array $blackCodes;

    /**
     * Static helper for finding matches with optional type filtering (for Group 3 1D decoding).
     *
     * @param int  $data       16-bit data to match
     * @param bool $white      True for white codes, false for black codes
     * @param bool $makeupOnly If true, only match make-up codes (>=64 pixels); if false, only match terminating codes (0-63 pixels)
     *
     * @return null|HorizontalCode Matching code or null if no match
     */
    public static function findCode(int $data, bool $white, bool $makeupOnly): ?HorizontalCode
    {
        static $instance = null;

        if ($instance === null) {
            $instance = new self;
        }

        $lookup = $white ? $instance->whiteCodes : $instance->blackCodes;

        foreach ($lookup as $code) {
            // Filter by type: makeupOnly=true means we want ONLY make-up codes
            // makeupOnly=false means we want ONLY terminating codes
            if ($makeupOnly && $code->isTerminating()) {
                continue; // Skip terminating codes when looking for make-up
            }

            if (!$makeupOnly && $code->isMakeup()) {
                continue; // Skip make-up codes when looking for terminating
            }

            if ($code->matches($data)) {
                return $code;
            }
        }

        return null;
    }

    public function __construct()
    {
        $this->whiteCodes = $this->loadWhiteCodes();
        $this->blackCodes = $this->loadBlackCodes();
    }

    public function findMatch32(int $data, bool $white): HorizontalCode
    {
        return $this->findMatch(($data >> 16) & 0xFFFF, $white);
    }

    public function findMatch(int $data, bool $white): HorizontalCode
    {
        $lookup = $white ? $this->whiteCodes : $this->blackCodes;

        foreach ($lookup as $code) {
            if ($code->matches($data)) {
                return $code;
            }
        }

        throw new RuntimeException('bad horizontal');
    }

    private function loadWhiteCodes(): array
    {
        $whiteTermCodes = [
            0x35,
            8,
            0x07,
            6,
            0x07,
            4,
            0x08,
            4,
            0x0B,
            4,
            0x0C,
            4,
            0x0E,
            4,
            0x0F,
            4,
            0x13,
            5,
            0x14,
            5,
            0x07,
            5,
            0x08,
            5,
            0x08,
            6,
            0x03,
            6,
            0x34,
            6,
            0x35,
            6,
            0x2A,
            6,
            0x2B,
            6,
            0x27,
            7,
            0x0C,
            7,
            0x08,
            7,
            0x17,
            7,
            0x03,
            7,
            0x04,
            7,
            0x28,
            7,
            0x2B,
            7,
            0x13,
            7,
            0x24,
            7,
            0x18,
            7,
            0x02,
            8,
            0x03,
            8,
            0x1A,
            8,
            0x1B,
            8,
            0x12,
            8,
            0x13,
            8,
            0x14,
            8,
            0x15,
            8,
            0x16,
            8,
            0x17,
            8,
            0x28,
            8,
            0x29,
            8,
            0x2A,
            8,
            0x2B,
            8,
            0x2C,
            8,
            0x2D,
            8,
            0x04,
            8,
            0x05,
            8,
            0x0A,
            8,
            0x0B,
            8,
            0x52,
            8,
            0x53,
            8,
            0x54,
            8,
            0x55,
            8,
            0x24,
            8,
            0x25,
            8,
            0x58,
            8,
            0x59,
            8,
            0x5A,
            8,
            0x5B,
            8,
            0x4A,
            8,
            0x4B,
            8,
            0x32,
            8,
            0x33,
            8,
            0x34,
            8,
        ];

        $whiteMakeUpCodes = [
            0x1B,
            5,
            0x12,
            5,
            0x17,
            6,
            0x37,
            7,
            0x36,
            8,
            0x37,
            8,
            0x64,
            8,
            0x65,
            8,
            0x68,
            8,
            0x67,
            8,
            0xCC,
            9,
            0xCD,
            9,
            0xD2,
            9,
            0xD3,
            9,
            0xD4,
            9,
            0xD5,
            9,
            0xD6,
            9,
            0xD7,
            9,
            0xD8,
            9,
            0xD9,
            9,
            0xDA,
            9,
            0xDB,
            9,
            0x98,
            9,
            0x99,
            9,
            0x9A,
            9,
            0x18,
            6,
            0x9B,
            9,
        ];

        $commonMakeUpCodes = [
            0x08,
            11,
            0x0C,
            11,
            0x0D,
            11,
            0x12,
            12,
            0x13,
            12,
            0x14,
            12,
            0x15,
            12,
            0x16,
            12,
            0x17,
            12,
            0x1C,
            12,
            0x1D,
            12,
            0x1E,
            12,
            0x1F,
            12,
        ];

        $codes = [];

        // White terminating codes
        for ($i = 0; $i < count($whiteTermCodes) / 2; $i++) {
            $bitsUsed = $whiteTermCodes[$i * 2 + 1];
            $value    = $whiteTermCodes[$i * 2] << (16 - $bitsUsed);
            $mask     = 0xFFFF << (16 - $bitsUsed);

            $codes[] = new HorizontalCode(
                $bitsUsed,
                $mask,
                $value,
                self::COLOR_WHITE,
                $i,
                true,
            );
        }

        // White make-up codes
        for ($i = 0; $i < count($whiteMakeUpCodes) / 2; $i++) {
            $bitsUsed = $whiteMakeUpCodes[$i * 2 + 1];
            $value    = $whiteMakeUpCodes[$i * 2] << (16 - $bitsUsed);
            $mask     = 0xFFFF << (16 - $bitsUsed);

            $codes[] = new HorizontalCode(
                $bitsUsed,
                $mask,
                $value,
                self::COLOR_WHITE,
                ($i + 1) * 64,
                false,
            );
        }

        // Common make-up codes
        for ($i = 0; $i < count($commonMakeUpCodes) / 2; $i++) {
            $bitsUsed = $commonMakeUpCodes[$i * 2 + 1];
            $value    = $commonMakeUpCodes[$i * 2] << (16 - $bitsUsed);
            $mask     = 0xFFFF << (16 - $bitsUsed);

            $codes[] = new HorizontalCode(
                $bitsUsed,
                $mask,
                $value,
                self::COLOR_BOTH,
                ($i + 1) * 64 + 1728,
                false,
            );
        }

        // Sort by bits used (descending)
        usort($codes, static fn ($a, $b) => $b->bitsUsed <=> $a->bitsUsed);

        return $codes;
    }

    private function loadBlackCodes(): array
    {
        $blackTermCodes = [
            0x37,
            10,
            0x02,
            3,
            0x03,
            2,
            0x02,
            2,
            0x03,
            3,
            0x03,
            4,
            0x02,
            4,
            0x03,
            5,
            0x05,
            6,
            0x04,
            6,
            0x04,
            7,
            0x05,
            7,
            0x07,
            7,
            0x04,
            8,
            0x07,
            8,
            0x18,
            9,
            0x17,
            10,
            0x18,
            10,
            0x08,
            10,
            0x67,
            11,
            0x68,
            11,
            0x6C,
            11,
            0x37,
            11,
            0x28,
            11,
            0x17,
            11,
            0x18,
            11,
            0xCA,
            12,
            0xCB,
            12,
            0xCC,
            12,
            0xCD,
            12,
            0x68,
            12,
            0x69,
            12,
            0x6A,
            12,
            0x6B,
            12,
            0xD2,
            12,
            0xD3,
            12,
            0xD4,
            12,
            0xD5,
            12,
            0xD6,
            12,
            0xD7,
            12,
            0x6C,
            12,
            0x6D,
            12,
            0xDA,
            12,
            0xDB,
            12,
            0x54,
            12,
            0x55,
            12,
            0x56,
            12,
            0x57,
            12,
            0x64,
            12,
            0x65,
            12,
            0x52,
            12,
            0x53,
            12,
            0x24,
            12,
            0x37,
            12,
            0x38,
            12,
            0x27,
            12,
            0x28,
            12,
            0x58,
            12,
            0x59,
            12,
            0x2B,
            12,
            0x2C,
            12,
            0x5A,
            12,
            0x66,
            12,
            0x67,
            12,
        ];

        $blackMakeUpCodes = [
            0x0F,
            10,
            0xC8,
            12,
            0xC9,
            12,
            0x5B,
            12,
            0x33,
            12,
            0x34,
            12,
            0x35,
            12,
            0x6C,
            13,
            0x6D,
            13,
            0x4A,
            13,
            0x4B,
            13,
            0x4C,
            13,
            0x4D,
            13,
            0x72,
            13,
            0x73,
            13,
            0x74,
            13,
            0x75,
            13,
            0x76,
            13,
            0x77,
            13,
            0x52,
            13,
            0x53,
            13,
            0x54,
            13,
            0x55,
            13,
            0x5A,
            13,
            0x5B,
            13,
            0x64,
            13,
            0x65,
            13,
        ];

        $commonMakeUpCodes = [
            0x08,
            11,
            0x0C,
            11,
            0x0D,
            11,
            0x12,
            12,
            0x13,
            12,
            0x14,
            12,
            0x15,
            12,
            0x16,
            12,
            0x17,
            12,
            0x1C,
            12,
            0x1D,
            12,
            0x1E,
            12,
            0x1F,
            12,
        ];

        $codes = [];

        // Black terminating codes
        for ($i = 0; $i < count($blackTermCodes) / 2; $i++) {
            $bitsUsed = $blackTermCodes[$i * 2 + 1];
            $value    = $blackTermCodes[$i * 2] << (16 - $bitsUsed);
            $mask     = 0xFFFF << (16 - $bitsUsed);

            $codes[] = new HorizontalCode(
                $bitsUsed,
                $mask,
                $value,
                self::COLOR_BLACK,
                $i,
                true,
            );
        }

        // Black make-up codes
        for ($i = 0; $i < count($blackMakeUpCodes) / 2; $i++) {
            $bitsUsed = $blackMakeUpCodes[$i * 2 + 1];
            $value    = $blackMakeUpCodes[$i * 2] << (16 - $bitsUsed);
            $mask     = 0xFFFF << (16 - $bitsUsed);

            $codes[] = new HorizontalCode(
                $bitsUsed,
                $mask,
                $value,
                self::COLOR_BLACK,
                ($i + 1) * 64,
                false,
            );
        }

        // Common make-up codes
        for ($i = 0; $i < count($commonMakeUpCodes) / 2; $i++) {
            $bitsUsed = $commonMakeUpCodes[$i * 2 + 1];
            $value    = $commonMakeUpCodes[$i * 2] << (16 - $bitsUsed);
            $mask     = 0xFFFF << (16 - $bitsUsed);

            $codes[] = new HorizontalCode(
                $bitsUsed,
                $mask,
                $value,
                self::COLOR_BOTH,
                ($i + 1) * 64 + 1728,
                false,
            );
        }

        // Sort by bits used (descending)
        usort($codes, static fn ($a, $b) => $b->bitsUsed <=> $a->bitsUsed);

        return $codes;
    }
}
