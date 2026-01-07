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
namespace PXP\PDF\Fpdf\Features\Merger;

use function array_keys;
use function ltrim;
use function str_replace;
use function strlen;
use Psr\Log\LoggerInterface;
use PXP\PDF\Fpdf\Core\Stream\PDFStream;
use PXP\PDF\Fpdf\Core\Stream\StreamEncoder;

/**
 * Optimized stream copier that avoids unnecessary decode/encode cycles.
 *
 * Strategy:
 * - If no resource remapping needed: copy encoded bytes directly (zero-copy)
 * - If resource remapping needed: decode → replace → re-encode
 * - Always uses encoded data when possible to minimize memory
 */
final class StreamCopier
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Copy stream with minimal memory usage.
     *
     * @param null|array<string, array<string, string>> $nameRemapping Map of old→new resource names
     */
    public function copyStream(
        PDFStream $sourceStream,
        ?array $nameRemapping = null
    ): PDFStream {
        // Fast path: no remapping needed - copy encoded bytes directly
        if ($nameRemapping === null || empty($nameRemapping)) {
            return $this->copyEncodedOnly($sourceStream);
        }

        // Slow path: need to remap resource names
        return $this->copyWithRemapping($sourceStream, $nameRemapping);
    }

    /**
     * Check if stream can be copied without decoding.
     *
     * Currently, we always need to decode if there's remapping.
     * Future optimization: binary search/replace for simple filters like FlateDecode.
     */
    public function canCopyWithoutDecode(
        PDFStream $stream,
        ?array $nameRemapping = null
    ): bool {
        if ($nameRemapping === null || empty($nameRemapping)) {
            return true;
        }

        // Future: implement binary replace for FlateDecode
        // $filters = $stream->getFilters();
        // return count($filters) === 1 && $filters[0] === 'FlateDecode';

        return false;
    }

    /**
     * Zero-copy: just copy encoded bytes and dictionary.
     */
    private function copyEncodedOnly(PDFStream $sourceStream): PDFStream
    {
        $dict    = clone $sourceStream->getDictionary();
        $encoded = $sourceStream->getEncodedData();

        $newStream = new PDFStream($dict, $encoded, true);
        $newStream->setEncodedData($encoded);

        $this->logger->debug('Stream copied without decode', [
            'encoded_size' => strlen($encoded),
        ]);

        // Free encoded reference
        unset($encoded);

        return $newStream;
    }

    /**
     * Remap resource names: decode → replace → re-encode.
     *
     * @param array<string, array<string, string>> $nameRemapping
     */
    private function copyWithRemapping(
        PDFStream $sourceStream,
        array $nameRemapping
    ): PDFStream {
        // Must decode to replace names
        $decoded = $sourceStream->getDecodedData();

        $this->logger->debug('Stream decoded for remapping', [
            'decoded_size' => strlen($decoded),
            'remap_types'  => array_keys($nameRemapping),
        ]);

        // Replace all resource names
        foreach ($nameRemapping as $resType => $map) {
            foreach ($map as $oldName => $newName) {
                // Replace /OldName with /NewName
                $oldPattern = '/' . ltrim($oldName, '/');
                $newPattern = '/' . $newName;
                $decoded    = str_replace($oldPattern, $newPattern, $decoded);
            }
        }

        // Re-encode with original filters
        $dict    = clone $sourceStream->getDictionary();
        $filters = $sourceStream->getFilters();

        if (!empty($filters)) {
            $encoder = new StreamEncoder;
            $encoded = $encoder->encode($decoded, $filters);

            $newStream = new PDFStream($dict, $encoded, true);
            $newStream->setEncodedData($encoded);

            $this->logger->debug('Stream re-encoded after remapping', [
                'encoded_size' => strlen($encoded),
                'filters'      => $filters,
            ]);

            // Free buffers
            unset($decoded, $encoded);
        } else {
            // No filters, use decoded data directly
            $newStream = new PDFStream($dict, $decoded, false);
            unset($decoded);
        }

        return $newStream;
    }
}
