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
namespace PXP\PDF\Fpdf\Output;

use const PHP_SAPI;
use function header;
use function headers_sent;
use function ob_clean;
use function ob_get_contents;
use function ob_get_length;
use function preg_match;
use PXP\PDF\Fpdf\Enum\OutputDestination;
use PXP\PDF\Fpdf\Exception\FpdfException;
use PXP\PDF\Fpdf\IO\FileWriterInterface;
use PXP\PDF\Fpdf\Text\TextRenderer;

final class OutputHandler
{
    public function __construct(
        private TextRenderer $textRenderer,
        private FileWriterInterface $fileWriter,
    ) {
    }

    public function output(string $buffer, OutputDestination $dest, string $name, bool $isUTF8 = false): string
    {
        $this->checkOutput();

        return match ($dest) {
            OutputDestination::INLINE   => $this->outputInline($buffer, $name, $isUTF8),
            OutputDestination::DOWNLOAD => $this->outputDownload($buffer, $name, $isUTF8),
            OutputDestination::FILE     => $this->outputFile($buffer, $name),
            OutputDestination::STRING   => $buffer,
        };
    }

    private function outputInline(string $buffer, string $name, bool $isUTF8): string
    {
        if (PHP_SAPI !== 'cli') {
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; ' . $this->textRenderer->httpEncode('filename', $name, $isUTF8));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
        }

        print $buffer;

        return '';
    }

    private function outputDownload(string $buffer, string $name, bool $isUTF8): string
    {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; ' . $this->textRenderer->httpEncode('filename', $name, $isUTF8));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        print $buffer;

        return '';
    }

    private function outputFile(string $buffer, string $name): string
    {
        $this->fileWriter->writeFile($name, $buffer);

        return '';
    }

    private function checkOutput(): void
    {
        if (PHP_SAPI !== 'cli') {
            if (headers_sent($file, $line)) {
                throw new FpdfException("Some data has already been output, can't send PDF file (output started at {$file}:{$line})");
            }
        }

        if (ob_get_length()) {
            $contents = ob_get_contents();

            if (preg_match('/^(\xEF\xBB\xBF)?\s*$/', $contents)) {
                ob_clean();
            } else {
                throw new FpdfException("Some data has already been output, can't send PDF file");
            }
        }
    }
}
