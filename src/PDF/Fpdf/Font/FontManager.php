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

namespace PXP\PDF\Fpdf\Font;

use PXP\PDF\Fpdf\Exception\FpdfException;

final class FontManager
{
    private const CORE_FONTS = ['courier', 'helvetica', 'times', 'symbol', 'zapfdingbats'];

    private array $fonts = [];
    private array $fontFiles = [];
    private array $encodings = [];
    private array $cmaps = [];

    public function __construct(
        private string $fontPath,
    ) {
    }

    public function addFont(string $family, string $style = '', string $file = '', string $dir = ''): void
    {
        $family = strtolower($family);
        if ($file === '') {
            $file = str_replace(' ', '', $family) . strtolower($style) . '.php';
        }

        $style = strtoupper($style);
        if ($style === 'IB') {
            $style = 'BI';
        }

        $fontKey = $family . $style;
        if (isset($this->fonts[$fontKey])) {
            return;
        }

        if (str_contains($file, '/') || str_contains($file, '\\')) {
            throw new FpdfException('Incorrect font definition file name: ' . $file);
        }

        if ($dir === '') {
            $dir = $this->fontPath;
        }

        if (!str_ends_with($dir, '/') && !str_ends_with($dir, '\\')) {
            $dir .= '/';
        }

        $info = $this->loadFont($dir . $file);
        $info['i'] = count($this->fonts) + 1;

        if (!empty($info['file'])) {
            $info['file'] = $dir . $info['file'];
            if ($info['type'] === 'TrueType') {
                $this->fontFiles[$info['file']] = ['length1' => $info['originalsize']];
            } else {
                $this->fontFiles[$info['file']] = ['length1' => $info['size1'], 'length2' => $info['size2']];
            }
        }

        $this->fonts[$fontKey] = $info;
    }

    public function getFont(string $family, string $style = ''): ?array
    {
        $family = strtolower($family);
        if ($family === 'arial') {
            $family = 'helvetica';
        }

        $style = strtoupper($style);
        if ($style === 'IB') {
            $style = 'BI';
        }

        if ($family === 'symbol' || $family === 'zapfdingbats') {
            $style = '';
        }

        $fontKey = $family . $style;

        if (!isset($this->fonts[$fontKey])) {
            if (in_array($family, self::CORE_FONTS, true)) {
                $this->addFont($family, $style);
            } else {
                throw new FpdfException('Undefined font: ' . $family . ' ' . $style);
            }
        }

        return $this->fonts[$fontKey];
    }

    public function getAllFonts(): array
    {
        return $this->fonts;
    }

    public function getFontFiles(): array
    {
        return $this->fontFiles;
    }

    public function getEncodings(): array
    {
        return $this->encodings;
    }

    public function getCmaps(): array
    {
        return $this->cmaps;
    }

    public function setEncoding(string $enc, int $n): void
    {
        $this->encodings[$enc] = $n;
    }

    public function setCmap(string $key, int $n): void
    {
        $this->cmaps[$key] = $n;
    }

    private function loadFont(string $path): array
    {
        include $path;
        if (!isset($name)) {
            throw new FpdfException('Could not include font definition file: ' . $path);
        }

        if (isset($enc)) {
            $enc = strtolower($enc);
        }

        if (!isset($subsetted)) {
            $subsetted = false;
        }

        return get_defined_vars();
    }
}
