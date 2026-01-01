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

namespace PXP\PDF\Fpdf\Image\Parser;

use PXP\PDF\Fpdf\Exception\FpdfException;
use PXP\PDF\Fpdf\IO\StreamFactoryInterface;

final class GifParser implements ImageParserInterface
{
    public function __construct(
        private PngParser $pngParser,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    public function parse(string $file): array
    {
        if (!function_exists('imagepng')) {
            throw new FpdfException('GD extension is required for GIF support');
        }

        if (!function_exists('imagecreatefromgif')) {
            throw new FpdfException('GD has no GIF read support');
        }

        if (!is_file($file) || !is_readable($file)) {
            throw new FpdfException('Missing or incorrect image file: ' . $file);
        }

        $im = @imagecreatefromgif($file);
        if (!$im) {
            throw new FpdfException('Missing or incorrect image file: ' . $file);
        }

        imageinterlace($im, false);
        ob_start();
        imagepng($im);
        $data = ob_get_clean();
        imagedestroy($im);


        $tempStream = $this->streamFactory->createTempStream('rb+');

        try {
            fwrite($tempStream, $data);
            rewind($tempStream);
            $info = $this->pngParser->parseStream($tempStream, $file);
        } finally {
            fclose($tempStream);
        }

        return $info;
    }

    public function supports(string $type): bool
    {
        return strtolower($type) === 'gif';
    }
}
