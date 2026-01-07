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
namespace PXP\PDF\Fpdf\Rendering\Font;

use function chr;
use function count;
use function get_defined_vars;
use function in_array;
use function is_array;
use function is_file;
use function md5;
use function realpath;
use function round;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function strtolower;
use function strtoupper;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use PXP\PDF\Fpdf\Events\Log\NullLogger;
use PXP\PDF\Fpdf\Exceptions\Exception\FpdfException;
use PXP\PDF\Fpdf\Utils\Cache\NullCache;

final class FontManager
{
    private const CORE_FONTS = ['courier', 'helvetica', 'times', 'symbol', 'zapfdingbats'];
    private array $fonts     = [];
    private array $fontFiles = [];
    private array $encodings = [];
    private array $cmaps     = [];
    private LoggerInterface $logger;
    private CacheItemPoolInterface $cache;

    public function __construct(
        private string $fontPath,
        private int $defaultCharWidth = 500,
        ?LoggerInterface $logger = null,
        ?CacheItemPoolInterface $cache = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
        $this->cache  = $cache ?? new NullCache;
    }

    public function setDefaultCharWidth(int $w): void
    {
        $this->defaultCharWidth = $w;
    }

    public function getDefaultCharWidth(): int
    {
        return $this->defaultCharWidth;
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

        $this->logger->debug('Font loading started', [
            'family'   => $family,
            'style'    => $style,
            'file'     => $file,
            'font_key' => $fontKey,
        ]);

        if (isset($this->fonts[$fontKey])) {
            $this->logger->debug('Font already loaded', [
                'font_key' => $fontKey,
            ]);

            return;
        }

        // Check cache
        $cacheKey  = 'pxp_pdf_font_' . md5($fontKey . $file);
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            $cachedInfo = $cacheItem->get();

            if (is_array($cachedInfo)) {
                $cachedInfo['i']       = count($this->fonts) + 1;
                $this->fonts[$fontKey] = $cachedInfo;
                $this->logger->debug('Font retrieved from cache', [
                    'font_key' => $fontKey,
                    'name'     => $cachedInfo['name'] ?? 'unknown',
                    'type'     => $cachedInfo['type'] ?? 'unknown',
                ]);

                return;
            }
        }

        if (str_contains($file, '/') || str_contains($file, '\\')) {
            $this->logger->error('Incorrect font definition file name', [
                'file' => $file,
            ]);

            throw new FpdfException('Incorrect font definition file name: ' . $file);
        }

        if ($dir === '') {
            $dir = $this->fontPath;
        }

        if (!str_ends_with($dir, '/') && !str_ends_with($dir, '\\')) {
            $dir .= '/';
        }

        $fontPath = $dir . $file;
        $this->logger->debug('Loading font from file', [
            'font_path' => $fontPath,
            'font_key'  => $fontKey,
        ]);

        $info      = $this->loadFont($fontPath);
        $info['i'] = count($this->fonts) + 1;

        if (!isset($info['cw']) || empty($info['cw'])) {
            $defaultCw = [];

            for ($ci = 32; $ci <= 255; $ci++) {
                $defaultCw[chr($ci)] = $this->defaultCharWidth;
            }

            $info['cw'] = $defaultCw;
        } else {
            $sum   = 0;
            $count = 0;

            foreach ($info['cw'] as $v) {
                $sum += (int) $v;
                $count++;
            }

            if ($count > 0) {
                $avg = (int) round($sum / $count);
            } else {
                $avg = $this->defaultCharWidth;
            }

            for ($ci = 32; $ci <= 255; $ci++) {
                $ch = chr($ci);

                if (!isset($info['cw'][$ch])) {
                    $info['cw'][$ch] = $avg;
                }
            }
        }

        if (!empty($info['file'])) {
            $info['file'] = $dir . $info['file'];

            if ($info['type'] === 'TrueType') {
                $this->fontFiles[$info['file']] = ['length1' => $info['originalsize']];
            } else {
                $this->fontFiles[$info['file']] = ['length1' => $info['size1'], 'length2' => $info['size2']];
            }
        }

        $this->fonts[$fontKey] = $info;

        // Cache the font info
        $cacheItem->set($info);
        $this->cache->save($cacheItem);

        $this->logger->debug('Font loaded and cached', [
            'font_key'        => $fontKey,
            'name'            => $info['name'] ?? 'unknown',
            'type'            => $info['type'] ?? 'unknown',
            'encoding'        => $info['enc'] ?? 'none',
            'character_count' => isset($info['cw']) ? count($info['cw']) : 0,
        ]);
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

        $this->logger->debug('Font retrieval requested', [
            'family'   => $family,
            'style'    => $style,
            'font_key' => $fontKey,
        ]);

        if (!isset($this->fonts[$fontKey])) {
            if (in_array($family, self::CORE_FONTS, true)) {
                $this->logger->debug('Loading core font', [
                    'font_key' => $fontKey,
                ]);
                $this->addFont($family, $style);
            } else {
                $this->logger->error('Undefined font', [
                    'family'   => $family,
                    'style'    => $style,
                    'font_key' => $fontKey,
                ]);

                throw new FpdfException('Undefined font: ' . $family . ' ' . $style);
            }
        }

        $font = $this->fonts[$fontKey];
        $this->logger->debug('Font retrieved', [
            'font_key' => $fontKey,
            'name'     => $font['name'] ?? 'unknown',
        ]);

        return $font;
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
        $this->logger->debug('Encoding set', [
            'encoding'      => $enc,
            'object_number' => $n,
        ]);
    }

    public function setCmap(string $key, int $n): void
    {
        $this->cmaps[$key] = $n;
        $this->logger->debug('CMap set', [
            'key'           => $key,
            'object_number' => $n,
        ]);
    }

    public function setFontObjectNumber(string $fontKey, int $n): void
    {
        if (isset($this->fonts[$fontKey])) {
            $this->fonts[$fontKey]['n'] = $n;
        }
    }

    private function loadFont(string $path): array
    {
        $absolutePath = realpath($path) ?: $path;

        $this->logger->debug('Loading font definition file', [
            'font_path' => $absolutePath,
        ]);

        if (!is_file($path)) {
            $this->logger->error('Font definition file not found', [
                'font_path' => $absolutePath,
            ]);

            throw new FpdfException('Could not include font definition file: ' . $path);
        }

        include $path;

        if (!isset($name)) {
            $this->logger->error('Invalid font definition file', [
                'font_path' => $absolutePath,
            ]);

            throw new FpdfException('Could not include font definition file: ' . $path);
        }

        if (isset($enc)) {
            $enc = strtolower($enc);
        }

        if (!isset($subsetted)) {
            $subsetted = false;
        }

        $info = get_defined_vars();
        $this->logger->debug('Font definition file loaded', [
            'font_path' => $absolutePath,
            'name'      => $name ?? 'unknown',
            'type'      => $info['type'] ?? 'unknown',
        ]);

        return $info;
    }
}
