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
namespace PXP\PDF\Fpdf\IO;

use function basename;
use function dirname;
use function fclose;
use function file_exists;
use function fopen;
use function fread;
use function fseek;
use function fwrite;
use function is_dir;
use function is_readable;
use function microtime;
use function mkdir;
use function realpath;
use function round;
use function stream_get_contents;
use function strlen;
use Psr\Log\LoggerInterface;
use PXP\PDF\Fpdf\Exception\FpdfException;
use PXP\PDF\Fpdf\Log\NullLogger;

/**
 * Default implementation of FileIOInterface.
 * Uses streamable file operations (fopen/fwrite/fread/fclose) for memory efficiency.
 */
final class FileIO implements FileIOInterface
{
    public function __construct(
        private ?LoggerInterface $logger = null,
    ) {
        $this->logger ??= new NullLogger;
    }

    public function readFile(string $path): string
    {
        $startTime    = microtime(true);
        $absolutePath = realpath($path) ?: $path;

        $this->logger->debug('File read operation started', [
            'file_path' => $absolutePath,
            'operation' => 'readFile',
        ]);

        if (!file_exists($path) || !is_readable($path)) {
            $this->logger->error('File not found or not readable', [
                'file_path' => $absolutePath,
                'operation' => 'readFile',
            ]);

            throw new FpdfException('File not found or not readable: ' . $path);
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            $this->logger->error('Could not open file for reading', [
                'file_path' => $absolutePath,
                'operation' => 'readFile',
            ]);

            throw new FpdfException('Could not open file for reading: ' . $path);
        }

        try {
            $contents = stream_get_contents($handle);

            if ($contents === false) {
                $this->logger->error('Could not read file contents', [
                    'file_path' => $absolutePath,
                    'operation' => 'readFile',
                ]);

                throw new FpdfException('Could not read file: ' . $path);
            }

            $duration = (microtime(true) - $startTime) * 1000;
            $this->logger->debug('File read operation completed', [
                'file_path'   => $absolutePath,
                'operation'   => 'readFile',
                'bytes_read'  => strlen($contents),
                'duration_ms' => round($duration, 2),
            ]);

            return $contents;
        } finally {
            fclose($handle);
        }
    }

    public function readFileChunk(string $path, int $length, int $offset = 0): string
    {
        $startTime    = microtime(true);
        $absolutePath = realpath($path) ?: $path;

        $this->logger->debug('File chunk read operation started', [
            'file_path' => $absolutePath,
            'operation' => 'readFileChunk',
            'offset'    => $offset,
            'length'    => $length,
        ]);

        if (!file_exists($path) || !is_readable($path)) {
            $this->logger->error('File not found or not readable', [
                'file_path' => $absolutePath,
                'operation' => 'readFileChunk',
            ]);

            throw new FpdfException('File not found or not readable: ' . $path);
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            $this->logger->error('Could not open file for reading', [
                'file_path' => $absolutePath,
                'operation' => 'readFileChunk',
            ]);

            throw new FpdfException('Could not open file for reading: ' . $path);
        }

        try {
            if (fseek($handle, $offset) !== 0) {
                $this->logger->error('Could not seek to offset in file', [
                    'file_path' => $absolutePath,
                    'operation' => 'readFileChunk',
                    'offset'    => $offset,
                ]);

                throw new FpdfException('Could not seek to offset in file: ' . $path);
            }

            $chunk = fread($handle, $length);

            if ($chunk === false) {
                $this->logger->error('Could not read chunk from file', [
                    'file_path' => $absolutePath,
                    'operation' => 'readFileChunk',
                ]);

                throw new FpdfException('Could not read chunk from file: ' . $path);
            }

            $duration = (microtime(true) - $startTime) * 1000;
            $this->logger->debug('File chunk read operation completed', [
                'file_path'   => $absolutePath,
                'operation'   => 'readFileChunk',
                'offset'      => $offset,
                'length'      => $length,
                'bytes_read'  => strlen($chunk),
                'duration_ms' => round($duration, 2),
            ]);

            return $chunk;
        } finally {
            fclose($handle);
        }
    }

    public function openReadStream(string $path)
    {
        $absolutePath = realpath($path) ?: $path;

        $this->logger->debug('Opening file read stream', [
            'file_path' => $absolutePath,
            'operation' => 'openReadStream',
        ]);

        if (!file_exists($path) || !is_readable($path)) {
            $this->logger->error('File not found or not readable', [
                'file_path' => $absolutePath,
                'operation' => 'openReadStream',
            ]);

            throw new FpdfException('File not found or not readable: ' . $path);
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            $this->logger->error('Could not open file for reading', [
                'file_path' => $absolutePath,
                'operation' => 'openReadStream',
            ]);

            throw new FpdfException('Could not open file for reading: ' . $path);
        }

        return $handle;
    }

    public function writeFile(string $path, string $content): void
    {
        $startTime    = microtime(true);
        $absolutePath = realpath(dirname($path)) ? '/' . basename($path) : $path;

        $this->logger->debug('File write operation started', [
            'file_path'   => $absolutePath,
            'operation'   => 'writeFile',
            'data_length' => strlen($content),
        ]);

        $dir = dirname($path);

        if ($dir !== '.' && $dir !== '' && !is_dir($dir)) {
            $this->logger->debug('Creating output directory', [
                'directory' => $dir,
                'operation' => 'writeFile',
            ]);

            if (!@mkdir($dir, 0o777, true) && !is_dir($dir)) {
                $this->logger->error('Could not create output directory', [
                    'directory' => $dir,
                    'operation' => 'writeFile',
                ]);

                throw new FpdfException('Could not create output directory: ' . $dir);
            }
        }

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            $this->logger->error('Could not open file for writing', [
                'file_path' => $absolutePath,
                'operation' => 'writeFile',
            ]);

            throw new FpdfException('Could not open file for writing: ' . $path);
        }

        try {
            $written = fwrite($handle, $content);

            if ($written === false || $written !== strlen($content)) {
                $this->logger->error('Could not write all content to file', [
                    'file_path'      => $absolutePath,
                    'operation'      => 'writeFile',
                    'expected_bytes' => strlen($content),
                    'written_bytes'  => $written !== false ? $written : 0,
                ]);

                throw new FpdfException('Could not write all content to file: ' . $path);
            }

            $duration = (microtime(true) - $startTime) * 1000;
            $this->logger->debug('File write operation completed', [
                'file_path'     => $absolutePath,
                'operation'     => 'writeFile',
                'bytes_written' => $written,
                'duration_ms'   => round($duration, 2),
            ]);
        } finally {
            fclose($handle);
        }
    }

    public function writeFileChunk(string $path, string $content, int $offset = 0): void
    {
        $startTime    = microtime(true);
        $absolutePath = realpath(dirname($path)) ? '/' . basename($path) : $path;

        $this->logger->debug('File chunk write operation started', [
            'file_path'   => $absolutePath,
            'operation'   => 'writeFileChunk',
            'offset'      => $offset,
            'data_length' => strlen($content),
        ]);

        $dir = dirname($path);

        if ($dir !== '.' && $dir !== '' && !is_dir($dir)) {
            $this->logger->debug('Creating output directory', [
                'directory' => $dir,
                'operation' => 'writeFileChunk',
            ]);

            if (!@mkdir($dir, 0o777, true) && !is_dir($dir)) {
                $this->logger->error('Could not create output directory', [
                    'directory' => $dir,
                    'operation' => 'writeFileChunk',
                ]);

                throw new FpdfException('Could not create output directory: ' . $dir);
            }
        }

        $handle = fopen($path, $offset > 0 ? 'r+b' : 'wb');

        if ($handle === false) {
            $this->logger->error('Could not open file for writing', [
                'file_path' => $absolutePath,
                'operation' => 'writeFileChunk',
            ]);

            throw new FpdfException('Could not open file for writing: ' . $path);
        }

        try {
            if ($offset > 0 && fseek($handle, $offset) !== 0) {
                $this->logger->error('Could not seek to offset in file', [
                    'file_path' => $absolutePath,
                    'operation' => 'writeFileChunk',
                    'offset'    => $offset,
                ]);

                throw new FpdfException('Could not seek to offset in file: ' . $path);
            }

            $written = fwrite($handle, $content);

            if ($written === false || $written !== strlen($content)) {
                $this->logger->error('Could not write chunk to file', [
                    'file_path'      => $absolutePath,
                    'operation'      => 'writeFileChunk',
                    'expected_bytes' => strlen($content),
                    'written_bytes'  => $written !== false ? $written : 0,
                ]);

                throw new FpdfException('Could not write chunk to file: ' . $path);
            }

            $duration = (microtime(true) - $startTime) * 1000;
            $this->logger->debug('File chunk write operation completed', [
                'file_path'     => $absolutePath,
                'operation'     => 'writeFileChunk',
                'offset'        => $offset,
                'bytes_written' => $written,
                'duration_ms'   => round($duration, 2),
            ]);
        } finally {
            fclose($handle);
        }
    }

    public function openWriteStream(string $path)
    {
        $absolutePath = realpath(dirname($path)) ? '/' . basename($path) : $path;

        $this->logger->debug('Opening file write stream', [
            'file_path' => $absolutePath,
            'operation' => 'openWriteStream',
        ]);

        $dir = dirname($path);

        if ($dir !== '.' && $dir !== '' && !is_dir($dir)) {
            $this->logger->debug('Creating output directory', [
                'directory' => $dir,
                'operation' => 'openWriteStream',
            ]);

            if (!@mkdir($dir, 0o777, true) && !is_dir($dir)) {
                $this->logger->error('Could not create output directory', [
                    'directory' => $dir,
                    'operation' => 'openWriteStream',
                ]);

                throw new FpdfException('Could not create output directory: ' . $dir);
            }
        }

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            $this->logger->error('Could not open file for writing', [
                'file_path' => $absolutePath,
                'operation' => 'openWriteStream',
            ]);

            throw new FpdfException('Could not open file for writing: ' . $path);
        }

        return $handle;
    }

    public function createTempStream(string $mode = 'rb+')
    {
        $this->logger->debug('Creating temporary stream', [
            'operation' => 'createTempStream',
            'mode'      => $mode,
        ]);

        $stream = fopen('php://temp', $mode);

        if ($stream === false) {
            $this->logger->error('Could not create temporary stream', [
                'operation' => 'createTempStream',
                'mode'      => $mode,
            ]);

            throw new FpdfException('Could not create temporary stream');
        }

        return $stream;
    }
}
