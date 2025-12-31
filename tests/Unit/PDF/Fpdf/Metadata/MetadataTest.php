<?php

declare(strict_types=1);

/**
 * Copyright (c) 2025 PXP
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/pxp-sh/pdf
 *
 */

namespace Test\Unit\PDF\Fpdf\Metadata;

use PHPUnit\Framework\TestCase;
use PXP\PDF\Fpdf\Metadata\Metadata;

/**
 * @covers \PXP\PDF\Fpdf\Metadata\Metadata
 */
final class MetadataTest extends TestCase
{
    private Metadata $metadata;

    protected function setUp(): void
    {
        $this->metadata = new Metadata('Test Producer');
    }

    public function testConstructorSetsProducer(): void
    {
        $data = $this->metadata->getAll();
        $this->assertArrayHasKey('Producer', $data);
        $this->assertSame('Test Producer', $data['Producer']);
    }

    public function testSetTitle(): void
    {
        $this->metadata->setTitle('Test Title');
        $data = $this->metadata->getAll();
        $this->assertArrayHasKey('Title', $data);
        $this->assertStringContainsString('Test Title', $data['Title']);
    }

    public function testSetTitleWithUtf8(): void
    {
        $this->metadata->setTitle('Test Title', true);
        $data = $this->metadata->getAll();
        $this->assertSame('Test Title', $data['Title']);
    }

    public function testSetAuthor(): void
    {
        $this->metadata->setAuthor('Test Author');
        $data = $this->metadata->getAll();
        $this->assertArrayHasKey('Author', $data);
    }

    public function testSetAuthorWithUtf8(): void
    {
        $this->metadata->setAuthor('Test Author', true);
        $data = $this->metadata->getAll();
        $this->assertSame('Test Author', $data['Author']);
    }

    public function testSetSubject(): void
    {
        $this->metadata->setSubject('Test Subject');
        $data = $this->metadata->getAll();
        $this->assertArrayHasKey('Subject', $data);
    }

    public function testSetSubjectWithUtf8(): void
    {
        $this->metadata->setSubject('Test Subject', true);
        $data = $this->metadata->getAll();
        $this->assertSame('Test Subject', $data['Subject']);
    }

    public function testSetKeywords(): void
    {
        $this->metadata->setKeywords('test, keywords');
        $data = $this->metadata->getAll();
        $this->assertArrayHasKey('Keywords', $data);
    }

    public function testSetKeywordsWithUtf8(): void
    {
        $this->metadata->setKeywords('test, keywords', true);
        $data = $this->metadata->getAll();
        $this->assertSame('test, keywords', $data['Keywords']);
    }

    public function testSetCreator(): void
    {
        $this->metadata->setCreator('Test Creator');
        $data = $this->metadata->getAll();
        $this->assertArrayHasKey('Creator', $data);
    }

    public function testSetCreatorWithUtf8(): void
    {
        $this->metadata->setCreator('Test Creator', true);
        $data = $this->metadata->getAll();
        $this->assertSame('Test Creator', $data['Creator']);
    }

    public function testSetCreationDate(): void
    {
        $timestamp = 1640995200; // 2022-01-01 00:00:00 UTC
        $this->metadata->setCreationDate($timestamp);
        $data = $this->metadata->getAll();
        $this->assertArrayHasKey('CreationDate', $data);
        $this->assertStringStartsWith('D:', $data['CreationDate']);
    }

    public function testGetAllReturnsAllMetadata(): void
    {
        $this->metadata->setTitle('Title');
        $this->metadata->setAuthor('Author');
        $this->metadata->setSubject('Subject');
        $this->metadata->setKeywords('Keywords');
        $this->metadata->setCreator('Creator');
        $this->metadata->setCreationDate(time());

        $data = $this->metadata->getAll();
        $this->assertArrayHasKey('Producer', $data);
        $this->assertArrayHasKey('Title', $data);
        $this->assertArrayHasKey('Author', $data);
        $this->assertArrayHasKey('Subject', $data);
        $this->assertArrayHasKey('Keywords', $data);
        $this->assertArrayHasKey('Creator', $data);
        $this->assertArrayHasKey('CreationDate', $data);
    }

    public function testEncodeUtf8WithNonAsciiCharacters(): void
    {
        // Test with ISO-8859-1 characters that need encoding
        $this->metadata->setTitle('Café');
        $data = $this->metadata->getAll();
        $this->assertArrayHasKey('Title', $data);
    }
}
