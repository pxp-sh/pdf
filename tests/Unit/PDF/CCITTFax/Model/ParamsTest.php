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
use PXP\PDF\CCITTFax\Model\Params;

final class ParamsTest extends TestCase
{
    public function test_default_values(): void
    {
        $params = new Params;

        $this->assertSame(-1, $params->getK());
        $this->assertFalse($params->getEndOfLine());
        $this->assertFalse($params->getEncodedByteAlign());
        $this->assertSame(1728, $params->getColumns());
        $this->assertSame(0, $params->getRows());
        $this->assertTrue($params->getEndOfBlock());
        $this->assertFalse($params->getBlackIs1());
        $this->assertSame(0, $params->getDamagedRowsBeforeError());
    }

    public function test_from_array(): void
    {
        $params = Params::fromArray([
            'K'                      => 0,
            'EndOfLine'              => true,
            'EncodedByteAlign'       => true,
            'Columns'                => 2048,
            'Rows'                   => 1024,
            'EndOfBlock'             => false,
            'BlackIs1'               => true,
            'DamagedRowsBeforeError' => 5,
        ]);

        $this->assertSame(0, $params->getK());
        $this->assertTrue($params->getEndOfLine());
        $this->assertTrue($params->getEncodedByteAlign());
        $this->assertSame(2048, $params->getColumns());
        $this->assertSame(1024, $params->getRows());
        $this->assertFalse($params->getEndOfBlock());
        $this->assertTrue($params->getBlackIs1());
        $this->assertSame(5, $params->getDamagedRowsBeforeError());
    }

    public function test_is_group4(): void
    {
        $params = new Params(k: -1);
        $this->assertTrue($params->isGroup4());
        $this->assertFalse($params->isPure1D());
        $this->assertFalse($params->isMixed());
    }

    public function test_is_pure_1d(): void
    {
        $params = new Params(k: 0);
        $this->assertFalse($params->isGroup4());
        $this->assertTrue($params->isPure1D());
        $this->assertFalse($params->isMixed());
    }

    public function test_is_mixed(): void
    {
        $params = new Params(k: 4);
        $this->assertFalse($params->isGroup4());
        $this->assertFalse($params->isPure1D());
        $this->assertTrue($params->isMixed());
    }

    public function test_standard_fax_width(): void
    {
        $params = new Params;
        $this->assertSame(1728, $params->getColumns(), 'Standard fax width should be 1728 pixels');
    }

    public function test_partial_array(): void
    {
        $params = Params::fromArray([
            'K'       => -1,
            'Columns' => 1024,
        ]);

        $this->assertSame(-1, $params->getK());
        $this->assertSame(1024, $params->getColumns());
        // Other params should use defaults
        $this->assertFalse($params->getEndOfLine());
        $this->assertSame(0, $params->getRows());
    }
}
