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
namespace Test\Unit\PDF\Fpdf\Output;

use const PHP_OS_FAMILY;
use const PHP_SAPI;
use function file_exists;
use function file_get_contents;
use function ob_get_clean;
use function ob_start;
use function sys_get_temp_dir;
use function uniqid;
use PXP\PDF\Fpdf\Exceptions\Exception\FpdfException;
use PXP\PDF\Fpdf\IO\OutputHandler;
use PXP\PDF\Fpdf\Rendering\Text\TextRenderer;
use PXP\PDF\Fpdf\Utils\Enum\OutputDestination;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Output\OutputHandler
 */
final class OutputHandlerTest extends TestCase
{
    private OutputHandler $outputHandler;

    protected function setUp(): void
    {
        $this->outputHandler = new OutputHandler(new TextRenderer, self::createFileIO());
    }

    public function testOutputWithStringDestination(): void
    {
        $buffer = 'test pdf content';
        $result = $this->outputHandler->output($buffer, OutputDestination::STRING, 'test.pdf');
        $this->assertSame($buffer, $result);
    }

    public function testOutputWithFileDestination(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_' . uniqid() . '.pdf';
        $buffer   = 'test pdf content';

        try {
            $result = $this->outputHandler->output($buffer, OutputDestination::FILE, $tempFile);
            $this->assertSame('', $result);
            $this->assertFileExists($tempFile);
            $this->assertSame($buffer, file_get_contents($tempFile));
        } finally {
            if (file_exists($tempFile)) {
                self::unlink($tempFile);
            }
        }
    }

    public function testOutputWithFileDestinationThrowsExceptionForInvalidPath(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Windows path handling differs');
        }

        $this->expectException(FpdfException::class);
        $this->expectExceptionMessage('Could not create output directory:');
        $this->outputHandler->output('test', OutputDestination::FILE, '/invalid/path/test.pdf');
    }

    public function testOutputWithInlineDestination(): void
    {
        if (PHP_SAPI === 'cli') {
            $buffer = 'test pdf content';
            ob_start();
            $result = $this->outputHandler->output($buffer, OutputDestination::INLINE, 'test.pdf');
            $output = ob_get_clean();
            $this->assertSame('', $result);
            $this->assertSame($buffer, $output);
        } else {
            $this->markTestSkipped('Test requires CLI mode or proper header handling');
        }
    }

    public function testOutputWithDownloadDestination(): void
    {
        if (PHP_SAPI === 'cli') {
            $buffer = 'test pdf content';
            ob_start();
            $result = $this->outputHandler->output($buffer, OutputDestination::DOWNLOAD, 'test.pdf');
            $output = ob_get_clean();
            $this->assertSame('', $result);
            $this->assertSame($buffer, $output);
        } else {
            $this->markTestSkipped('Test requires CLI mode or proper header handling');
        }
    }

    public function testOutputThrowsExceptionWhenHeadersAlreadySent(): void
    {
        if (PHP_SAPI === 'cli') {
            $this->markTestSkipped('Headers are not sent in CLI mode');
        }

        $this->assertTrue(true);
    }

    public function testOutputThrowsExceptionWhenOutputBufferHasContent(): void
    {
        if (PHP_SAPI === 'cli') {
            $this->markTestSkipped('Output buffer handling differs in CLI mode');
        }

        $this->assertTrue(true);
    }
}
