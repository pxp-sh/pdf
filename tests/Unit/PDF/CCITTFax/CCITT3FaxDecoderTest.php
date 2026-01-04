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
namespace Tests\Unit\PDF\CCITTFax;

use PHPUnit\Framework\TestCase;
use PXP\PDF\CCITTFax\CCITT3FaxDecoder;
use PXP\PDF\CCITTFax\CCITTFaxParams;

final class CCITT3FaxDecoderTest extends TestCase
{
    public function test_decode_simple_white_line(): void
    {
        // Encode a simple line: 8 white pixels
        // White terminating code for 8: bits 101011 (0x2B), 6 bits
        // Followed by black terminating code for 0: bits 0000110111 (0x037), 10 bits
        // But we need to construct proper bit stream

        // For now, skip encoding tests - we need proper encoder or binary test data
        $this->markTestIncomplete('Need encoded test data from reference implementation');
    }

    public function test_params_validation(): void
    {
        $params = new CCITTFaxParams(k: 0, columns: 8);

        $this->assertTrue($params->isPure1D());
        $this->assertSame(8, $params->getColumns());
    }

    public function test_decoder_instantiation(): void
    {
        $params  = new CCITTFaxParams(k: 0, columns: 8);
        $decoder = new CCITT3FaxDecoder($params, '');

        $this->assertInstanceOf(CCITT3FaxDecoder::class, $decoder);
    }
}
