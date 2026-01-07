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
namespace Test\Unit\PDF\Fpdf\Structure;

use function sys_get_temp_dir;
use PXP\PDF\Fpdf\Core\Structure\PDFStructure;
use PXP\PDF\Fpdf\Features\Link\LinkManager;
use PXP\PDF\Fpdf\Features\Metadata\Metadata;
use PXP\PDF\Fpdf\Rendering\Font\FontManager;
use PXP\PDF\Fpdf\Rendering\Image\ImageHandler;
use PXP\PDF\Fpdf\Rendering\Page\PageManager;
use PXP\PDF\Fpdf\Rendering\Text\TextRenderer;
use PXP\PDF\Fpdf\Utils\Buffer\Buffer;
use PXP\PDF\Fpdf\Utils\Enum\LayoutMode;
use PXP\PDF\Fpdf\Utils\Enum\PageOrientation;
use PXP\PDF\Fpdf\Utils\ValueObject\PageSize;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Structure\PDFStructure
 */
final class PDFStructureTest extends TestCase
{
    private Buffer $buffer;
    private PageManager $pageManager;
    private FontManager $fontManager;
    private ImageHandler $imageHandler;
    private LinkManager $linkManager;
    private Metadata $metadata;
    private TextRenderer $textRenderer;
    private PDFStructure $pdfStructure;

    protected function setUp(): void
    {
        $fileIO            = self::createFileIO();
        $this->buffer      = new Buffer;
        $this->pageManager = new PageManager(
            $fileIO,
            self::getLogger(),
            self::getEventDispatcher(),
        );
        $this->fontManager = new FontManager(
            sys_get_temp_dir(),
            500,
            self::getLogger(),
            self::getCache(),
        );
        $this->imageHandler = new ImageHandler(
            $fileIO,
            $fileIO,
            self::getLogger(),
            self::getCache(),
        );
        $this->linkManager  = new LinkManager;
        $this->metadata     = new Metadata('Test Producer');
        $this->textRenderer = new TextRenderer;
        $this->pdfStructure = new PDFStructure(
            $this->buffer,
            $this->pageManager,
            $this->fontManager,
            $this->imageHandler,
            $this->linkManager,
            $this->metadata,
            $this->textRenderer,
            $fileIO,
            compress: false,
            withAlpha: false,
            pdfVersion: '1.3',
            logger: self::getLogger(),
            dispatcher: self::getEventDispatcher(),
        );
    }

    public function testBuildCreatesPdfHeader(): void
    {
        $this->pageManager->addPage();
        $pageSize = PageSize::fromString('a4', 72.0 / 25.4);

        $this->pdfStructure->build(
            PageOrientation::PORTRAIT,
            $pageSize,
            72.0 / 25.4,
            'default',
            LayoutMode::DEFAULT,
        );

        $content = $this->buffer->getContent();
        $this->assertStringStartsWith('%PDF-1.3', $content);
    }

    public function testBuildWithDifferentPdfVersion(): void
    {
        $this->pdfStructure->setPdfVersion('1.4');
        $this->pageManager->addPage();
        $pageSize = PageSize::fromString('a4', 72.0 / 25.4);

        $this->pdfStructure->build(
            PageOrientation::PORTRAIT,
            $pageSize,
            72.0 / 25.4,
            'default',
            LayoutMode::DEFAULT,
        );

        $content = $this->buffer->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $content);
    }

    public function testBuildWithMultiplePages(): void
    {
        $this->pageManager->addPage();
        $this->pageManager->addPage();
        $this->pageManager->addPage();
        $pageSize = PageSize::fromString('a4', 72.0 / 25.4);

        $this->pdfStructure->build(
            PageOrientation::PORTRAIT,
            $pageSize,
            72.0 / 25.4,
            'default',
            LayoutMode::DEFAULT,
        );

        $content = $this->buffer->getContent();
        $this->assertStringContainsString('/Count 3', $content);
    }

    public function testBuildWithPortraitOrientation(): void
    {
        $this->pageManager->addPage();
        $pageSize = PageSize::fromString('a4', 72.0 / 25.4);

        $this->pdfStructure->build(
            PageOrientation::PORTRAIT,
            $pageSize,
            72.0 / 25.4,
            'default',
            LayoutMode::DEFAULT,
        );

        $content = $this->buffer->getContent();
        $this->assertStringContainsString('/MediaBox', $content);
    }

    public function testBuildWithLandscapeOrientation(): void
    {
        $this->pageManager->addPage();
        $pageSize = PageSize::fromString('a4', 72.0 / 25.4);

        $this->pdfStructure->build(
            PageOrientation::LANDSCAPE,
            $pageSize,
            72.0 / 25.4,
            'default',
            LayoutMode::DEFAULT,
        );

        $content = $this->buffer->getContent();
        $this->assertStringContainsString('/MediaBox', $content);
    }

    public function testBuildWithFullpageZoom(): void
    {
        $this->pageManager->addPage();
        $pageSize = PageSize::fromString('a4', 72.0 / 25.4);

        $this->pdfStructure->build(
            PageOrientation::PORTRAIT,
            $pageSize,
            72.0 / 25.4,
            'fullpage',
            LayoutMode::DEFAULT,
        );

        $content = $this->buffer->getContent();
        $this->assertStringContainsString('/Fit', $content);
    }

    public function testBuildWithFullwidthZoom(): void
    {
        $this->pageManager->addPage();
        $pageSize = PageSize::fromString('a4', 72.0 / 25.4);

        $this->pdfStructure->build(
            PageOrientation::PORTRAIT,
            $pageSize,
            72.0 / 25.4,
            'fullwidth',
            LayoutMode::DEFAULT,
        );

        $content = $this->buffer->getContent();
        $this->assertStringContainsString('/FitH', $content);
    }

    public function testBuildWithRealZoom(): void
    {
        $this->pageManager->addPage();
        $pageSize = PageSize::fromString('a4', 72.0 / 25.4);

        $this->pdfStructure->build(
            PageOrientation::PORTRAIT,
            $pageSize,
            72.0 / 25.4,
            'real',
            LayoutMode::DEFAULT,
        );

        $content = $this->buffer->getContent();
        $this->assertStringContainsString('/XYZ', $content);
    }

    public function testBuildWithFloatZoom(): void
    {
        $this->pageManager->addPage();
        $pageSize = PageSize::fromString('a4', 72.0 / 25.4);

        $this->pdfStructure->build(
            PageOrientation::PORTRAIT,
            $pageSize,
            72.0 / 25.4,
            150.0,
            LayoutMode::DEFAULT,
        );

        $content = $this->buffer->getContent();
        $this->assertStringContainsString('/XYZ', $content);
    }

    public function testBuildWithSingleLayoutMode(): void
    {
        $this->pageManager->addPage();
        $pageSize = PageSize::fromString('a4', 72.0 / 25.4);

        $this->pdfStructure->build(
            PageOrientation::PORTRAIT,
            $pageSize,
            72.0 / 25.4,
            'default',
            LayoutMode::SINGLE,
        );

        $content = $this->buffer->getContent();
        $this->assertStringContainsString('/SinglePage', $content);
    }

    public function testBuildWithContinuousLayoutMode(): void
    {
        $this->pageManager->addPage();
        $pageSize = PageSize::fromString('a4', 72.0 / 25.4);

        $this->pdfStructure->build(
            PageOrientation::PORTRAIT,
            $pageSize,
            72.0 / 25.4,
            'default',
            LayoutMode::CONTINUOUS,
        );

        $content = $this->buffer->getContent();
        $this->assertStringContainsString('/OneColumn', $content);
    }

    public function testBuildWithTwoLayoutMode(): void
    {
        $this->pageManager->addPage();
        $pageSize = PageSize::fromString('a4', 72.0 / 25.4);

        $this->pdfStructure->build(
            PageOrientation::PORTRAIT,
            $pageSize,
            72.0 / 25.4,
            'default',
            LayoutMode::TWO,
        );

        $content = $this->buffer->getContent();
        $this->assertStringContainsString('/TwoColumnLeft', $content);
    }

    public function testBuildIncludesMetadata(): void
    {
        $this->metadata->setTitle('Test Title');
        $this->metadata->setAuthor('Test Author');
        $this->pageManager->addPage();
        $pageSize = PageSize::fromString('a4', 72.0 / 25.4);

        $this->pdfStructure->build(
            PageOrientation::PORTRAIT,
            $pageSize,
            72.0 / 25.4,
            'default',
            LayoutMode::DEFAULT,
        );

        $content = $this->buffer->getContent();
        $this->assertStringContainsString('/Title', $content);
        $this->assertStringContainsString('/Author', $content);
    }

    public function testBuildIncludesXref(): void
    {
        $this->pageManager->addPage();
        $pageSize = PageSize::fromString('a4', 72.0 / 25.4);

        $this->pdfStructure->build(
            PageOrientation::PORTRAIT,
            $pageSize,
            72.0 / 25.4,
            'default',
            LayoutMode::DEFAULT,
        );

        $content = $this->buffer->getContent();
        $this->assertStringContainsString('xref', $content);
        $this->assertStringContainsString('trailer', $content);
        $this->assertStringContainsString('startxref', $content);
        $this->assertStringEndsWith("%%EOF\n", $content);
    }

    public function testSetWithAlpha(): void
    {
        $this->pdfStructure->setWithAlpha(true);
        $this->pageManager->addPage();
        $pageSize = PageSize::fromString('a4', 72.0 / 25.4);

        $this->pdfStructure->build(
            PageOrientation::PORTRAIT,
            $pageSize,
            72.0 / 25.4,
            'default',
            LayoutMode::DEFAULT,
        );

        $content = $this->buffer->getContent();
        $this->assertStringContainsString('/Group', $content);
        $this->assertStringContainsString('/Transparency', $content);
    }

    public function testBuildWithPageLinks(): void
    {
        $this->pageManager->addPage();
        $link = $this->linkManager->addLink();
        $this->linkManager->setLink($link, 100.0, 1);
        $this->linkManager->addPageLink(1, 10.0, 20.0, 30.0, 40.0, $link);
        $pageSize = PageSize::fromString('a4', 72.0 / 25.4);

        $this->pdfStructure->build(
            PageOrientation::PORTRAIT,
            $pageSize,
            72.0 / 25.4,
            'default',
            LayoutMode::DEFAULT,
        );

        $content = $this->buffer->getContent();
        $this->assertStringContainsString('/Annots', $content);
    }

    public function testBuildWithStringUrlLink(): void
    {
        $this->pageManager->addPage();
        $this->linkManager->addPageLink(1, 10.0, 20.0, 30.0, 40.0, 'https://example.com');
        $pageSize = PageSize::fromString('a4', 72.0 / 25.4);

        $this->pdfStructure->build(
            PageOrientation::PORTRAIT,
            $pageSize,
            72.0 / 25.4,
            'default',
            LayoutMode::DEFAULT,
        );

        $content = $this->buffer->getContent();
        $this->assertStringContainsString('/URI', $content);
    }
}
