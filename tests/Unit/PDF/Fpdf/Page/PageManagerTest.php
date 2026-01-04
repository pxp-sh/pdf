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
namespace Test\Unit\PDF\Fpdf\Page;

use PXP\PDF\Fpdf\Page\PageManager;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Page\PageManager
 */
final class PageManagerTest extends TestCase
{
    private PageManager $pageManager;

    protected function setUp(): void
    {
        $this->pageManager = new PageManager(
            self::createFileIO(),
            self::getLogger(),
            self::getEventDispatcher(),
        );
    }

    public function testInitialState(): void
    {
        $this->assertSame(0, $this->pageManager->getCurrentPage());
    }

    public function testAddPageIncrementsPageNumber(): void
    {
        $page1 = $this->pageManager->addPage();
        $this->assertSame(1, $page1);
        $this->assertSame(1, $this->pageManager->getCurrentPage());

        $page2 = $this->pageManager->addPage();
        $this->assertSame(2, $page2);
        $this->assertSame(2, $this->pageManager->getCurrentPage());
    }

    public function testGetPageContentReturnsEmptyStringForNewPage(): void
    {
        $this->pageManager->addPage();
        $this->assertSame('', $this->pageManager->getPageContent(1));
    }

    public function testAppendContent(): void
    {
        $page = $this->pageManager->addPage();
        $this->pageManager->appendContent($page, 'test content');

        $this->assertSame("test content\n", $this->pageManager->getPageContent($page));
    }

    public function testAppendContentMultipleTimes(): void
    {
        $page = $this->pageManager->addPage();
        $this->pageManager->appendContent($page, 'line 1');
        $this->pageManager->appendContent($page, 'line 2');
        $this->pageManager->appendContent($page, 'line 3');

        $content = $this->pageManager->getPageContent($page);
        $this->assertSame("line 1\nline 2\nline 3\n", $content);
    }

    public function testAppendContentToNonExistentPage(): void
    {
        $this->pageManager->appendContent(999, 'content');
        $this->assertSame("content\n", $this->pageManager->getPageContent(999));
    }

    public function testGetPageContentReturnsEmptyStringForNonExistentPage(): void
    {
        $this->assertSame('', $this->pageManager->getPageContent(999));
    }

    public function testGetAllPages(): void
    {
        $page1 = $this->pageManager->addPage();
        $page2 = $this->pageManager->addPage();
        $this->pageManager->appendContent($page1, 'content 1');
        $this->pageManager->appendContent($page2, 'content 2');

        $allPages = $this->pageManager->getAllPages();
        $this->assertCount(2, $allPages);
        $this->assertArrayHasKey(1, $allPages);
        $this->assertArrayHasKey(2, $allPages);
        $this->assertSame("content 1\n", $allPages[1]);
        $this->assertSame("content 2\n", $allPages[2]);
    }

    public function testSetPageInfo(): void
    {
        $page = $this->pageManager->addPage();
        $this->pageManager->setPageInfo($page, 'size', [595.28, 841.89]);

        $info = $this->pageManager->getPageInfo($page);
        $this->assertArrayHasKey('size', $info);
        $this->assertSame([595.28, 841.89], $info['size']);
    }

    public function testSetPageInfoMultipleKeys(): void
    {
        $page = $this->pageManager->addPage();
        $this->pageManager->setPageInfo($page, 'size', [595.28, 841.89]);
        $this->pageManager->setPageInfo($page, 'rotation', 90);

        $info = $this->pageManager->getPageInfo($page);
        $this->assertCount(2, $info);
        $this->assertArrayHasKey('size', $info);
        $this->assertArrayHasKey('rotation', $info);
    }

    public function testGetPageInfoReturnsEmptyArrayForNewPage(): void
    {
        $page = $this->pageManager->addPage();
        $info = $this->pageManager->getPageInfo($page);
        $this->assertSame([], $info);
    }

    public function testGetPageInfoReturnsEmptyArrayForNonExistentPage(): void
    {
        $this->assertSame([], $this->pageManager->getPageInfo(999));
    }

    public function testGetAllPageInfo(): void
    {
        $page1 = $this->pageManager->addPage();
        $page2 = $this->pageManager->addPage();
        $this->pageManager->setPageInfo($page1, 'size', [595.28, 841.89]);
        $this->pageManager->setPageInfo($page2, 'rotation', 90);

        $allInfo = $this->pageManager->getAllPageInfo();
        $this->assertCount(2, $allInfo);
        $this->assertArrayHasKey(1, $allInfo);
        $this->assertArrayHasKey(2, $allInfo);
    }

    public function testReplaceInPage(): void
    {
        $page = $this->pageManager->addPage();
        $this->pageManager->appendContent($page, 'old content');
        $this->pageManager->replaceInPage($page, 'old', 'new');

        $this->assertSame("new content\n", $this->pageManager->getPageContent($page));
    }

    public function testReplaceInPageMultipleOccurrences(): void
    {
        $page = $this->pageManager->addPage();
        $this->pageManager->appendContent($page, 'test test test');
        $this->pageManager->replaceInPage($page, 'test', 'new');

        $this->assertSame("new new new\n", $this->pageManager->getPageContent($page));
    }

    public function testReplaceInPageNonExistentPage(): void
    {
        $this->pageManager->replaceInPage(999, 'old', 'new');
        $this->assertSame('', $this->pageManager->getPageContent(999));
    }
}
