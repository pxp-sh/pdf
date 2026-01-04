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
namespace Test\Unit\PDF\Fpdf\Exception;

use Exception;
use PXP\PDF\Fpdf\Exception\FpdfException;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Exception\FpdfException
 */
final class FpdfExceptionTest extends TestCase
{
    public function testExceptionExtendsException(): void
    {
        $exception = new FpdfException;
        $this->assertInstanceOf(Exception::class, $exception);
    }

    public function testExceptionWithMessage(): void
    {
        $message   = 'Test error message';
        $exception = new FpdfException($message);
        $this->assertSame($message, $exception->getMessage());
    }

    public function testExceptionWithMessageAndCode(): void
    {
        $message   = 'Test error message';
        $code      = 500;
        $exception = new FpdfException($message, $code);
        $this->assertSame($message, $exception->getMessage());
        $this->assertSame($code, $exception->getCode());
    }

    public function testExceptionWithPreviousException(): void
    {
        $previous  = new Exception('Previous exception');
        $exception = new FpdfException('Test error', 0, $previous);
        $this->assertSame($previous, $exception->getPrevious());
    }
}
