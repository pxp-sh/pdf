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
namespace Test\Unit\PDF\Fpdf\Link;

use PXP\PDF\Fpdf\Features\Link\LinkManager;
use Test\TestCase;

/**
 * @covers \PXP\PDF\Fpdf\Link\LinkManager
 */
final class LinkManagerTest extends TestCase
{
    private LinkManager $linkManager;

    protected function setUp(): void
    {
        $this->linkManager = new LinkManager;
    }

    public function testAddLinkReturnsIncrementalId(): void
    {
        $link1 = $this->linkManager->addLink();
        $link2 = $this->linkManager->addLink();
        $link3 = $this->linkManager->addLink();

        $this->assertSame(1, $link1);
        $this->assertSame(2, $link2);
        $this->assertSame(3, $link3);
    }

    public function testSetLinkWithValidValues(): void
    {
        $link = $this->linkManager->addLink();
        $this->linkManager->setLink($link, 100.5, 2);

        $linkData = $this->linkManager->getLink($link);
        $this->assertNotNull($linkData);
        $this->assertSame([2, 100.5], $linkData);
    }

    public function testSetLinkWithNegativeYDefaultsToZero(): void
    {
        $link = $this->linkManager->addLink();
        $this->linkManager->setLink($link, -50.0, 1);

        $linkData = $this->linkManager->getLink($link);
        $this->assertNotNull($linkData);
        $this->assertEquals([1, 0], $linkData);
    }

    public function testSetLinkWithNegativePageDefaultsToZero(): void
    {
        $link = $this->linkManager->addLink();
        $this->linkManager->setLink($link, 100.0, -1);

        $linkData = $this->linkManager->getLink($link);
        $this->assertNotNull($linkData);
        $this->assertSame([0, 100.0], $linkData);
    }

    public function testGetLinkReturnsNullForNonExistentLink(): void
    {
        $this->assertNull($this->linkManager->getLink(999));
    }

    public function testAddPageLink(): void
    {
        $this->linkManager->addPageLink(1, 10.0, 20.0, 30.0, 40.0, 1);

        $pageLinks = $this->linkManager->getPageLinks(1);
        $this->assertCount(1, $pageLinks);
        $this->assertSame([10.0, 20.0, 30.0, 40.0, 1], $pageLinks[0]);
    }

    public function testAddMultiplePageLinks(): void
    {
        $this->linkManager->addPageLink(1, 10.0, 20.0, 30.0, 40.0, 1);
        $this->linkManager->addPageLink(1, 50.0, 60.0, 70.0, 80.0, 2);

        $pageLinks = $this->linkManager->getPageLinks(1);
        $this->assertCount(2, $pageLinks);
        $this->assertSame([10.0, 20.0, 30.0, 40.0, 1], $pageLinks[0]);
        $this->assertSame([50.0, 60.0, 70.0, 80.0, 2], $pageLinks[1]);
    }

    public function testAddPageLinkWithStringUrl(): void
    {
        $this->linkManager->addPageLink(1, 10.0, 20.0, 30.0, 40.0, 'https://example.com');

        $pageLinks = $this->linkManager->getPageLinks(1);
        $this->assertCount(1, $pageLinks);
        $this->assertSame([10.0, 20.0, 30.0, 40.0, 'https://example.com'], $pageLinks[0]);
    }

    public function testGetPageLinksReturnsEmptyArrayForNonExistentPage(): void
    {
        $this->assertSame([], $this->linkManager->getPageLinks(999));
    }

    public function testGetAllLinks(): void
    {
        $link1 = $this->linkManager->addLink();
        $link2 = $this->linkManager->addLink();
        $this->linkManager->setLink($link1, 100.0, 1);
        $this->linkManager->setLink($link2, 200.0, 2);

        $allLinks = $this->linkManager->getAllLinks();
        $this->assertCount(2, $allLinks);
        $this->assertSame([1, 100.0], $allLinks[$link1]);
        $this->assertSame([2, 200.0], $allLinks[$link2]);
    }

    public function testGetAllPageLinks(): void
    {
        $this->linkManager->addPageLink(1, 10.0, 20.0, 30.0, 40.0, 1);
        $this->linkManager->addPageLink(2, 50.0, 60.0, 70.0, 80.0, 2);

        $allPageLinks = $this->linkManager->getAllPageLinks();
        $this->assertCount(2, $allPageLinks);
        $this->assertArrayHasKey(1, $allPageLinks);
        $this->assertArrayHasKey(2, $allPageLinks);
    }

    public function testClearPageLinks(): void
    {
        $this->linkManager->addPageLink(1, 10.0, 20.0, 30.0, 40.0, 1);
        $this->linkManager->clearPageLinks(1);

        $this->assertSame([], $this->linkManager->getPageLinks(1));
    }

    public function testClearPageLinksDoesNotAffectOtherPages(): void
    {
        $this->linkManager->addPageLink(1, 10.0, 20.0, 30.0, 40.0, 1);
        $this->linkManager->addPageLink(2, 50.0, 60.0, 70.0, 80.0, 2);
        $this->linkManager->clearPageLinks(1);

        $this->assertSame([], $this->linkManager->getPageLinks(1));
        $this->assertCount(1, $this->linkManager->getPageLinks(2));
    }
}
