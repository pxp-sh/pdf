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
use PXP\PDF\Fpdf\Exceptions\Exception\FpdfException;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Exception\FpdfException
 */
final class FpdfExceptionTest extends TestCase
{
    public function testExceptionExtendsException(): void
    {
        $fpdfException = new FpdfException;
        $this->assertInstanceOf(Exception::class, $fpdfException);
    }

    public function testExceptionWithMessage(): void
    {
        $message       = 'Test error message';
        $fpdfException = new FpdfException($message);
        $this->assertSame($message, $fpdfException->getMessage());
    }

    public function testExceptionWithMessageAndCode(): void
    {
        $message       = 'Test error message';
        $code          = 500;
        $fpdfException = new FpdfException($message, $code);
        $this->assertSame($message, $fpdfException->getMessage());
        $this->assertSame($code, $fpdfException->getCode());
    }

    public function testExceptionWithPreviousException(): void
    {
        $previous      = new Exception('Previous exception');
        $fpdfException = new FpdfException('Test error', 0, $previous);
        $this->assertSame($previous, $fpdfException->getPrevious());
    }
}
