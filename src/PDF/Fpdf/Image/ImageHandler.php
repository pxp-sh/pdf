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

namespace PXP\PDF\Fpdf\Image;

use PXP\PDF\Fpdf\Cache\NullCache;
use PXP\PDF\Fpdf\Exception\FpdfException;
use PXP\PDF\Fpdf\IO\FileReaderInterface;
use PXP\PDF\Fpdf\IO\StreamFactoryInterface;
use PXP\PDF\Fpdf\Image\Parser\GifParser;
use PXP\PDF\Fpdf\Image\Parser\ImageParserInterface;
use PXP\PDF\Fpdf\Image\Parser\JpegParser;
use PXP\PDF\Fpdf\Image\Parser\PngParser;
use PXP\PDF\Fpdf\Log\NullLogger;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

final class ImageHandler
{
    private array $images = [];
    private array $parsers = [];

    public function __construct(
        FileReaderInterface $fileReader,
        StreamFactoryInterface $streamFactory,
        ?LoggerInterface $logger = null,
        ?CacheItemPoolInterface $cache = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->cache = $cache ?? new NullCache();

        $pngParser = new PngParser($fileReader, $streamFactory);
        $this->parsers[] = new JpegParser($fileReader);
        $this->parsers[] = $pngParser;
        $this->parsers[] = new GifParser($pngParser, $streamFactory);
    }

    private LoggerInterface $logger;
    private CacheItemPoolInterface $cache;

    public function addImage(string $file, string $type = ''): array
    {
        $absolutePath = realpath($file) ?: $file;

        $this->logger->debug('Image loading started', [
            'file_path' => $absolutePath,
            'type' => $type ?: 'auto-detect',
        ]);

        if ($file === '') {
            $this->logger->error('Image file name is empty');
            throw new FpdfException('Image file name is empty');
        }

        // Check cache first
        $cacheKey = 'pxp_pdf_image_' . md5($file);
        $cacheItem = $this->cache->getItem($cacheKey);
        if ($cacheItem->isHit()) {
            $cachedInfo = $cacheItem->get();
            if (is_array($cachedInfo)) {
                $this->images[$file] = $cachedInfo;
                $this->logger->debug('Image retrieved from cache', [
                    'file_path' => $absolutePath,
                    'dimensions' => ['w' => $cachedInfo['w'] ?? 0, 'h' => $cachedInfo['h'] ?? 0],
                ]);
                return $cachedInfo;
            }
        }

        if (isset($this->images[$file])) {
            $this->logger->debug('Image already loaded', [
                'file_path' => $absolutePath,
            ]);
            return $this->images[$file];
        }

        if ($type === '') {
            $pos = strrpos($file, '.');
            if (!$pos) {
                $this->logger->error('Image file has no extension and no type was specified', [
                    'file_path' => $absolutePath,
                ]);
                throw new FpdfException('Image file has no extension and no type was specified: ' . $file);
            }

            $type = substr($file, $pos + 1);
        }

        $type = strtolower($type);
        if ($type === 'jpeg') {
            $type = 'jpg';
        }

        $this->logger->debug('Detecting image type', [
            'file_path' => $absolutePath,
            'detected_type' => $type,
        ]);

        $parser = $this->findParser($type);
        if ($parser === null) {
            $this->logger->error('Unsupported image type', [
                'file_path' => $absolutePath,
                'type' => $type,
            ]);
            throw new FpdfException('Unsupported image type: ' . $type);
        }

        $this->logger->debug('Parsing image', [
            'file_path' => $absolutePath,
            'type' => $type,
            'parser' => get_class($parser),
        ]);

        $info = $parser->parse($file);
        $info['i'] = count($this->images) + 1;
        $this->images[$file] = $info;

        // Cache the processed image info
        $cacheItem->set($info);
        $this->cache->save($cacheItem);

        $this->logger->debug('Image processed and cached', [
            'file_path' => $absolutePath,
            'type' => $type,
            'dimensions' => ['w' => $info['w'] ?? 0, 'h' => $info['h'] ?? 0],
            'color_space' => $info['cs'] ?? 'unknown',
            'bits_per_component' => $info['bpc'] ?? 0,
            'has_alpha' => isset($info['smask']) || isset($info['trns']),
        ]);

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

    public function setImageObjectNumber(string $file, int $n): void
    {
        if (isset($this->images[$file])) {
            $this->images[$file]['n'] = $n;
            $this->logger->debug('Image object number set', [
                'file_path' => realpath($file) ?: $file,
                'object_number' => $n,
            ]);
        }
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
