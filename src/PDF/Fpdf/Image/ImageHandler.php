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

namespace PXP\PDF\Fpdf\Image;

use PXP\PDF\Fpdf\Exception\FpdfException;
use PXP\PDF\Fpdf\Image\Parser\GifParser;
use PXP\PDF\Fpdf\Image\Parser\ImageParserInterface;
use PXP\PDF\Fpdf\Image\Parser\JpegParser;
use PXP\PDF\Fpdf\Image\Parser\PngParser;

final class ImageHandler
{
    private array $images = [];
    private array $parsers = [];

    public function __construct()
    {
        $pngParser = new PngParser();
        $this->parsers[] = new JpegParser();
        $this->parsers[] = $pngParser;
        $this->parsers[] = new GifParser($pngParser);
    }

    public function addImage(string $file, string $type = ''): array
    {
        if ($file === '') {
            throw new FpdfException('Image file name is empty');
        }

        if (isset($this->images[$file])) {
            return $this->images[$file];
        }

        if ($type === '') {
            $pos = strrpos($file, '.');
            if (!$pos) {
                throw new FpdfException('Image file has no extension and no type was specified: ' . $file);
            }

            $type = substr($file, $pos + 1);
        }

        $type = strtolower($type);
        if ($type === 'jpeg') {
            $type = 'jpg';
        }

        $parser = $this->findParser($type);
        if ($parser === null) {
            throw new FpdfException('Unsupported image type: ' . $type);
        }

        $info = $parser->parse($file);
        $info['i'] = count($this->images) + 1;
        $this->images[$file] = $info;

        return $info;
    }

    public function getImage(string $file): ?array
    {
        return $this->images[$file] ?? null;
    }

    public function getAllImages(): array
    {
        return $this->images;
    }

    private function findParser(string $type): ?ImageParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($type)) {
                return $parser;
            }
        }

        return null;
    }
}
