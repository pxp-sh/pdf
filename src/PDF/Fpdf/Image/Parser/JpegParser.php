<?php

declare(strict_types=1);

/**
 * Copyright (c) 2025 PXP
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/pxp-sh/pdf
 *
 */

namespace PXP\PDF\Fpdf\Image\Parser;

use PXP\PDF\Fpdf\Exception\FpdfException;

final class JpegParser implements ImageParserInterface
{
    public function parse(string $file): array
    {
        $a = getimagesize($file);
        if (!$a) {
            throw new FpdfException('Missing or incorrect image file: ' . $file);
        }

        if ($a[2] !== 2) {
            throw new FpdfException('Not a JPEG file: ' . $file);
        }

        if (!isset($a['channels']) || $a['channels'] === 3) {
            $colspace = 'DeviceRGB';
        } elseif ($a['channels'] === 4) {
            $colspace = 'DeviceCMYK';
        } else {
            $colspace = 'DeviceGray';
        }

        $bpc = $a['bits'] ?? 8;
        $data = $this->readFile($file);
        if ($data === null) {
            throw new FpdfException('Could not read image file: ' . $file);
        }

        return [
            'w' => $a[0],
            'h' => $a[1],
            'cs' => $colspace,
            'bpc' => $bpc,
            'f' => 'DCTDecode',
            'data' => $data,
        ];
    }

    public function supports(string $type): bool
    {
        return in_array(strtolower($type), ['jpg', 'jpeg'], true);
    }

    private function readFile(string $file): ?string
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $contents = stream_get_contents($handle);
            return $contents !== false ? $contents : null;
        } finally {
            fclose($handle);
        }
    }
}
