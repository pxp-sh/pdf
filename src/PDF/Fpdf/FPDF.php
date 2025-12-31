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

namespace PXP\PDF\Fpdf;

use PXP\PDF\Fpdf\Buffer\Buffer;
use PXP\PDF\Fpdf\Color\ColorManager;
use PXP\PDF\Fpdf\Enum\LayoutMode;
use PXP\PDF\Fpdf\Enum\OutputDestination;
use PXP\PDF\Fpdf\Enum\PageOrientation;
use PXP\PDF\Fpdf\Enum\Unit;
use PXP\PDF\Fpdf\Enum\ZoomMode;
use PXP\PDF\Fpdf\Exception\FpdfException;
use PXP\PDF\Fpdf\Font\FontManager;
use PXP\PDF\Fpdf\Image\ImageHandler;
use PXP\PDF\Fpdf\Link\LinkManager;
use PXP\PDF\Fpdf\Metadata\Metadata;
use PXP\PDF\Fpdf\Output\OutputHandler;
use PXP\PDF\Fpdf\Page\PageManager;
use PXP\PDF\Fpdf\Structure\PDFStructure;
use PXP\PDF\Fpdf\Text\TextRenderer;
use PXP\PDF\Fpdf\ValueObject\PageSize;

class FPDF
{
    public const VERSION = '1.86-pxp-sh';

    private int $state = 0;
    private float $k;
    private PageOrientation $defOrientation;
    private PageOrientation $curOrientation;
    private PageSize $defPageSize;
    private PageSize $curPageSize;
    private int $curRotation = 0;
    private float $wPt;
    private float $hPt;
    private float $w;
    private float $h;
    private float $lMargin;
    private float $tMargin;
    private float $rMargin;
    private float $bMargin;
    private float $cMargin;
    private float $x;
    private float $y;
    private float $lasth = 0;
    private float $lineWidth;
    private string $fontFamily = '';
    private string $fontStyle = '';
    private bool $underline = false;
    private ?array $currentFont = null;
    private float $fontSizePt = 12;
    private float $fontSize;
    private float $ws = 0;
    private bool $autoPageBreak;
    private float $pageBreakTrigger;
    private bool $inHeader = false;
    private bool $inFooter = false;
    private string $aliasNbPages = '';
    private string|float $zoomMode = 'default';
    private LayoutMode $layoutMode = LayoutMode::DEFAULT;
    private bool $compress;
    private bool $withAlpha = false;
    private string $pdfVersion = '1.3';

    private Buffer $buffer;
    private PageManager $pageManager;
    private FontManager $fontManager;
    private ImageHandler $imageHandler;
    private LinkManager $linkManager;
    private Metadata $metadata;
    private ColorManager $colorManager;
    private TextRenderer $textRenderer;
    private OutputHandler $outputHandler;
    private PDFStructure $pdfStructure;

    public function __construct(
        string|PageOrientation $orientation = 'P',
        string|Unit $unit = 'mm',
        string|array|PageSize $size = 'A4',
    ) {
        // Initialize components
        $this->buffer = new Buffer();
        $this->textRenderer = new TextRenderer();
        $this->colorManager = new ColorManager();
        $this->linkManager = new LinkManager();
        $this->imageHandler = new ImageHandler();
        $this->pageManager = new PageManager();

        // Font path
        $fontPath = defined('FPDF_FONTPATH') ? FPDF_FONTPATH : dirname(__DIR__, 3) . '/font/';
        $this->fontManager = new FontManager($fontPath);

        // Unit and scale factor
        if (is_string($unit)) {
            $unit = Unit::fromString($unit);
        }

        $this->k = $unit->getScaleFactor();

        // Page size
        if (is_string($size)) {
            $pageSize = PageSize::fromString($size, $this->k);
        } elseif (is_array($size)) {
            $pageSize = PageSize::fromArray($size, $this->k);
        } else {
            $pageSize = $size;
        }

        $this->defPageSize = $pageSize;
        $this->curPageSize = $pageSize;

        // Orientation
        if (is_string($orientation)) {
            $orientation = PageOrientation::fromString($orientation);
        }

        $this->defOrientation = $orientation;
        $this->curOrientation = $orientation;

        if ($orientation === PageOrientation::PORTRAIT) {
            $this->w = $pageSize->getWidth();
            $this->h = $pageSize->getHeight();
        } else {
            $this->w = $pageSize->getHeight();
            $this->h = $pageSize->getWidth();
        }

        $this->wPt = $this->w * $this->k;
        $this->hPt = $this->h * $this->k;

        // Page rotation
        $this->curRotation = 0;

        // Margins (1 cm)
        $margin = 28.35 / $this->k;
        $this->setMargins($margin, $margin);

        // Cell margin (1 mm)
        $this->cMargin = $margin / 10;

        // Line width (0.2 mm)
        $this->lineWidth = 0.567 / $this->k;

        // Automatic page break
        $this->setAutoPageBreak(true, 2 * $margin);

        // Default display mode
        $this->setDisplayMode('default');

        // Compression
        $this->setCompression(true);

        // Metadata
        $this->metadata = new Metadata('FPDF ' . self::VERSION);

        // Initialize PDF structure
        $this->pdfStructure = new PDFStructure(
            $this->buffer,
            $this->pageManager,
            $this->fontManager,
            $this->imageHandler,
            $this->linkManager,
            $this->metadata,
            $this->textRenderer,
            $this->compress,
            $this->withAlpha,
            $this->pdfVersion,
        );

        $this->outputHandler = new OutputHandler($this->textRenderer);
    }

    public function setMargins(float $left, float $top, ?float $right = null): void
    {
        $this->lMargin = $left;
        $this->tMargin = $top;
        $this->rMargin = $right ?? $left;
    }

    public function setLeftMargin(float $margin): void
    {
        $this->lMargin = $margin;
        if ($this->pageManager->getCurrentPage() > 0 && $this->x < $margin) {
            $this->x = $margin;
        }
    }

    public function setTopMargin(float $margin): void
    {
        $this->tMargin = $margin;
    }

    public function setRightMargin(float $margin): void
    {
        $this->rMargin = $margin;
    }

    public function setAutoPageBreak(bool $auto, float $margin = 0): void
    {
        $this->autoPageBreak = $auto;
        $this->bMargin = $margin;
        $this->pageBreakTrigger = $this->h - $margin;
    }

    public function setDisplayMode(string|float $zoom, string|LayoutMode $layout = 'default'): void
    {
        $this->zoomMode = ZoomMode::fromValue($zoom);
        if (is_string($layout)) {
            $this->layoutMode = LayoutMode::fromString($layout);
        } else {
            $this->layoutMode = $layout;
        }
    }

    public function setCompression(bool $compress): void
    {
        $this->compress = function_exists('gzcompress') ? $compress : false;
    }

    public function setTitle(string $title, bool $isUTF8 = false): void
    {
        $this->metadata->setTitle($title, $isUTF8);
    }

    public function setAuthor(string $author, bool $isUTF8 = false): void
    {
        $this->metadata->setAuthor($author, $isUTF8);
    }

    public function setSubject(string $subject, bool $isUTF8 = false): void
    {
        $this->metadata->setSubject($subject, $isUTF8);
    }

    public function setKeywords(string $keywords, bool $isUTF8 = false): void
    {
        $this->metadata->setKeywords($keywords, $isUTF8);
    }

    public function setCreator(string $creator, bool $isUTF8 = false): void
    {
        $this->metadata->setCreator($creator, $isUTF8);
    }

    public function aliasNbPages(string $alias = '{nb}'): void
    {
        $this->aliasNbPages = $alias;
    }

    public function error(string $msg): never
    {
        throw new FpdfException('FPDF error: ' . $msg);
    }

    public function close(): void
    {
        if ($this->state === 3) {
            return;
        }

        if ($this->pageManager->getCurrentPage() === 0) {
            $this->addPage();
        }

        // Page footer
        $this->inFooter = true;
        $this->footer();
        $this->inFooter = false;

        // Close page
        $this->endPage();

        // Close document
        $this->endDoc();
    }

    public function addPage(
        string|PageOrientation $orientation = '',
        string|array|PageSize $size = '',
        int $rotation = 0,
    ): void {
        if ($this->state === 3) {
            $this->error('The document is closed');
        }

        $family = $this->fontFamily;
        $style = $this->fontStyle . ($this->underline ? 'U' : '');
        $fontSize = $this->fontSizePt;
        $lw = $this->lineWidth;
        $dc = $this->colorManager->getDrawColor();
        $fc = $this->colorManager->getFillColor();
        $tc = $this->colorManager->getTextColor();
        $cf = $this->colorManager->hasColorFlag();

        if ($this->pageManager->getCurrentPage() > 0) {
            // Page footer
            $this->inFooter = true;
            $this->footer();
            $this->inFooter = false;

            // Close page
            $this->endPage();
        }

        // Start new page
        $this->beginPage($orientation, $size, $rotation);

        // Set line cap style to square
        $this->out('2 J');

        // Set line width
        $this->lineWidth = $lw;
        $this->out(sprintf('%.2F w', $lw * $this->k));

        // Set font
        if ($family) {
            $this->setFont($family, $style, $fontSize);
        }

        // Set colors
        $this->colorManager->setDrawColor(0, null, null);
        if ($dc !== '0 G') {
            $this->out($dc);
        }

        $this->colorManager->setFillColor(0, null, null);
        if ($fc !== '0 g') {
            $this->out($fc);
        }

        $this->colorManager->setTextColor(0, null, null);

        // Page header
        $this->inHeader = true;
        $this->header();
        $this->inHeader = false;

        // Restore line width
        if ($this->lineWidth !== $lw) {
            $this->lineWidth = $lw;
            $this->out(sprintf('%.2F w', $lw * $this->k));
        }

        // Restore font
        if ($family) {
            $this->setFont($family, $style, $fontSize);
        }

        // Restore colors
        if ($this->colorManager->getDrawColor() !== $dc) {
            $this->colorManager->setDrawColor(0, null, null);
            $this->out($dc);
        }

        if ($this->colorManager->getFillColor() !== $fc) {
            $this->colorManager->setFillColor(0, null, null);
            $this->out($fc);
        }

        $this->colorManager->setTextColor(0, null, null);
    }

    public function header(): void
    {
        // To be implemented in your own inherited class
    }

    public function footer(): void
    {
        // To be implemented in your own inherited class
    }

    public function pageNo(): int
    {
        return $this->pageManager->getCurrentPage();
    }

    public function setDrawColor(int $r, ?int $g = null, ?int $b = null): void
    {
        $color = $this->colorManager->setDrawColor($r, $g, $b);
        if ($this->pageManager->getCurrentPage() > 0) {
            $this->out($color);
        }
    }

    public function setFillColor(int $r, ?int $g = null, ?int $b = null): void
    {
        $color = $this->colorManager->setFillColor($r, $g, $b);
        if ($this->pageManager->getCurrentPage() > 0) {
            $this->out($color);
        }
    }

    public function setTextColor(int $r, ?int $g = null, ?int $b = null): void
    {
        $this->colorManager->setTextColor($r, $g, $b);
    }

    public function getStringWidth(string $s): float
    {
        if ($this->currentFont === null) {
            $this->error('No font has been set');
        }

        return $this->textRenderer->getStringWidth($s, $this->currentFont['cw'], $this->fontSize);
    }

    public function setLineWidth(float $width): void
    {
        $this->lineWidth = $width;
        if ($this->pageManager->getCurrentPage() > 0) {
            $this->out(sprintf('%.2F w', $width * $this->k));
        }
    }

    public function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->out(sprintf(
            '%.2F %.2F m %.2F %.2F l S',
            $x1 * $this->k,
            ($this->h - $y1) * $this->k,
            $x2 * $this->k,
            ($this->h - $y2) * $this->k,
        ));
    }

    public function rect(float $x, float $y, float $w, float $h, string $style = ''): void
    {
        $op = match ($style) {
            'F' => 'f',
            'FD', 'DF' => 'B',
            default => 'S',
        };

        $this->out(sprintf(
            '%.2F %.2F %.2F %.2F re %s',
            $x * $this->k,
            ($this->h - $y) * $this->k,
            $w * $this->k,
            -$h * $this->k,
            $op,
        ));
    }

    public function addFont(string $family, string $style = '', string $file = '', string $dir = ''): void
    {
        $this->fontManager->addFont($family, $style, $file, $dir);
    }

    public function setFont(string $family, string $style = '', float $size = 0): void
    {
        if ($family === '') {
            $family = $this->fontFamily;
        } else {
            $family = strtolower($family);
        }

        $style = strtoupper($style);
        if (str_contains($style, 'U')) {
            $this->underline = true;
            $style = str_replace('U', '', $style);
        } else {
            $this->underline = false;
        }

        if ($style === 'IB') {
            $style = 'BI';
        }

        if ($size <= 0.0) {
            $size = $this->fontSizePt;
        }

        // Test if font is already selected
        if ($this->fontFamily === $family && $this->fontStyle === $style && $this->fontSizePt === $size) {
            return;
        }

        // Get or load font
        $font = $this->fontManager->getFont($family, $style);
        if ($font === null) {
            $this->error('Undefined font: ' . $family . ' ' . $style);
        }

        // Select it
        $this->fontFamily = $family;
        $this->fontStyle = $style;
        $this->fontSizePt = $size;
        $this->fontSize = $size / $this->k;
        $this->currentFont = $font;

        if ($this->pageManager->getCurrentPage() > 0) {
            $this->out(sprintf('BT /F%d %.2F Tf ET', $this->currentFont['i'], $this->fontSizePt));
        }
    }

    public function setFontSize(float $size): void
    {
        if ($this->fontSizePt === $size) {
            return;
        }

        $this->fontSizePt = $size;
        $this->fontSize = $size / $this->k;
        if ($this->pageManager->getCurrentPage() > 0 && $this->currentFont !== null) {
            $this->out(sprintf('BT /F%d %.2F Tf ET', $this->currentFont['i'], $this->fontSizePt));
        }
    }

    public function addLink(): int
    {
        return $this->linkManager->addLink();
    }

    public function setLink(int $link, float $y = 0, int $page = -1): void
    {
        if ($y < 0) {
            $y = $this->y;
        }

        if ($page < 0) {
            $page = $this->pageManager->getCurrentPage();
        }

        $this->linkManager->setLink($link, $y, $page);
    }

    public function link(float $x, float $y, float $w, float $h, int|string $link): void
    {
        $this->linkManager->addPageLink(
            $this->pageManager->getCurrentPage(),
            $x * $this->k,
            $this->hPt - $y * $this->k,
            $w * $this->k,
            $h * $this->k,
            $link,
        );
    }

    public function text(float $x, float $y, string $txt): void
    {
        if ($this->currentFont === null) {
            $this->error('No font has been set');
        }

        $s = sprintf(
            'BT %.2F %.2F Td (%s) Tj ET',
            $x * $this->k,
            ($this->h - $y) * $this->k,
            $this->textRenderer->escape($txt),
        );

        if ($this->underline && $txt !== '') {
            $s .= ' ' . $this->doUnderline($x, $y, $txt);
        }

        if ($this->colorManager->hasColorFlag()) {
            $s = 'q ' . $this->colorManager->getTextColor() . ' ' . $s . ' Q';
        }

        $this->out($s);
    }

    public function acceptPageBreak(): bool
    {
        return $this->autoPageBreak;
    }

    public function cell(
        float $w,
        float $h = 0,
        string $txt = '',
        int|string $border = 0,
        int $ln = 0,
        string $align = '',
        bool $fill = false,
        int|string $link = '',
    ): void {
        $k = $this->k;
        if ($this->y + $h > $this->pageBreakTrigger && !$this->inHeader && !$this->inFooter && $this->acceptPageBreak()) {
            $x = $this->x;
            $ws = $this->ws;
            if ($ws > 0) {
                $this->ws = 0;
                $this->out('0 Tw');
            }

            $this->addPage($this->curOrientation, $this->curPageSize, $this->curRotation);
            $this->x = $x;
            if ($ws > 0) {
                $this->ws = $ws;
                $this->out(sprintf('%.3F Tw', $ws * $k));
            }
        }

        if ($w <= 0.0) {
            $w = $this->w - $this->rMargin - $this->x;
        }

        $s = '';
        if ($fill || $border === 1) {
            $op = $fill ? (($border === 1) ? 'B' : 'f') : 'S';
            $s = sprintf(
                '%.2F %.2F %.2F %.2F re %s ',
                $this->x * $k,
                ($this->h - $this->y) * $k,
                $w * $k,
                -$h * $k,
                $op,
            );
        }

        if (is_string($border)) {
            $x = $this->x;
            $y = $this->y;
            if (str_contains($border, 'L')) {
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x * $k, ($this->h - $y) * $k, $x * $k, ($this->h - ($y + $h)) * $k);
            }

            if (str_contains($border, 'T')) {
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x * $k, ($this->h - $y) * $k, ($x + $w) * $k, ($this->h - $y) * $k);
            }

            if (str_contains($border, 'R')) {
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', ($x + $w) * $k, ($this->h - $y) * $k, ($x + $w) * $k, ($this->h - ($y + $h)) * $k);
            }

            if (str_contains($border, 'B')) {
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x * $k, ($this->h - ($y + $h)) * $k, ($x + $w) * $k, ($this->h - ($y + $h)) * $k);
            }
        }

        if ($txt !== '') {
            if ($this->currentFont === null) {
                $this->error('No font has been set');
            }

            if ($align === 'R') {
                $dx = $w - $this->cMargin - $this->getStringWidth($txt);
            } elseif ($align === 'C') {
                $dx = ($w - $this->getStringWidth($txt)) / 2;
            } else {
                $dx = $this->cMargin;
            }

            if ($this->colorManager->hasColorFlag()) {
                $s .= 'q ' . $this->colorManager->getTextColor() . ' ';
            }

            $s .= sprintf(
                'BT %.2F %.2F Td (%s) Tj ET',
                ($this->x + $dx) * $k,
                ($this->h - ($this->y + 0.5 * $h + 0.3 * $this->fontSize)) * $k,
                $this->textRenderer->escape($txt),
            );

            if ($this->underline) {
                $s .= ' ' . $this->doUnderline($this->x + $dx, $this->y + 0.5 * $h + 0.3 * $this->fontSize, $txt);
            }

            if ($this->colorManager->hasColorFlag()) {
                $s .= ' Q';
            }

            if ($link) {
                $this->link($this->x + $dx, $this->y + 0.5 * $h - 0.5 * $this->fontSize, $this->getStringWidth($txt), $this->fontSize, $link);
            }
        }

        if ($s) {
            $this->out($s);
        }

        $this->lasth = $h;
        if ($ln > 0) {
            $this->y += $h;
            if ($ln === 1) {
                $this->x = $this->lMargin;
            }
        } else {
            $this->x += $w;
        }
    }

    public function multiCell(
        float $w,
        float $h,
        string $txt,
        int|string $border = 0,
        string $align = 'J',
        bool $fill = false,
    ): void {
        if ($this->currentFont === null) {
            $this->error('No font has been set');
        }

        $cw = $this->currentFont['cw'];
        if ($w <= 0.0) {
            $w = $this->w - $this->rMargin - $this->x;
        }

        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->fontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] === "\n") {
            $nb--;
        }

        $b = 0;
        $b2 = '';
        if ($border) {
            if ($border === 1) {
                $border = 'LTRB';
                $b = 'LRT';
                $b2 = 'LR';
            } else {
                $b2 = '';
                if (str_contains($border, 'L')) {
                    $b2 .= 'L';
                }

                if (str_contains($border, 'R')) {
                    $b2 .= 'R';
                }

                $b = str_contains($border, 'T') ? $b2 . 'T' : $b2;
            }
        }

        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $ns = 0;
        $nl = 1;
        $ls = 0.0;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c === "\n") {
                if ($this->ws > 0) {
                    $this->ws = 0;
                    $this->out('0 Tw');
                }

                $this->cell($w, $h, substr($s, $j, $i - $j), $b, 2, $align, $fill);
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $ns = 0;
                $nl++;
                if ($border && $nl === 2) {
                    $b = $b2;
                }

                continue;
            }

            if ($c === ' ') {
                $sep = $i;
                $ls = $l;
                $ns++;
            }

            $l += $cw[$c] ?? 0;
            if ($l > $wmax) {
                if ($sep === -1) {
                    if ($i === $j) {
                        $i++;
                    }

                    if ($this->ws > 0) {
                        $this->ws = 0;
                        $this->out('0 Tw');
                    }

                    $this->cell($w, $h, substr($s, $j, $i - $j), $b, 2, $align, $fill);
                } else {
                    if ($align === 'J' && $ns > 1) {
                        $this->ws = ($wmax - $ls) / 1000 * $this->fontSize / ($ns - 1);
                        $this->out(sprintf('%.3F Tw', $this->ws * $this->k));
                    }

                    $this->cell($w, $h, substr($s, $j, $sep - $j), $b, 2, $align, $fill);
                    $i = $sep + 1;
                }

                $sep = -1;
                $j = $i;
                $l = 0;
                $ns = 0;
                $nl++;
                if ($border && $nl === 2) {
                    $b = $b2;
                }
            } else {
                $i++;
            }
        }

        if ($this->ws > 0) {
            $this->ws = 0;
            $this->out('0 Tw');
        }

        if ($border && str_contains($border, 'B')) {
            $b .= 'B';
        }

        $this->cell($w, $h, substr($s, $j, $i - $j), $b, 2, $align, $fill);
        $this->x = $this->lMargin;
    }

    public function write(float $h, string $txt, int|string $link = ''): void
    {
        if ($this->currentFont === null) {
            $this->error('No font has been set');
        }

        $cw = $this->currentFont['cw'];
        $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->fontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c === "\n") {
                $this->cell($w, $h, substr($s, $j, $i - $j), 0, 2, '', false, $link);
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                if ($nl === 1) {
                    $this->x = $this->lMargin;
                    $w = $this->w - $this->rMargin - $this->x;
                    $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->fontSize;
                }

                $nl++;
                continue;
            }

            if ($c === ' ') {
                $sep = $i;
            }

            $l += $cw[$c] ?? 0;
            if ($l > $wmax) {
                if ($sep === -1) {
                    if ($this->x > $this->lMargin) {
                        $this->x = $this->lMargin;
                        $this->y += $h;
                        $w = $this->w - $this->rMargin - $this->x;
                        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->fontSize;
                        $i++;
                        $nl++;
                        continue;
                    }

                    if ($i === $j) {
                        $i++;
                    }

                    $this->cell($w, $h, substr($s, $j, $i - $j), 0, 2, '', false, $link);
                } else {
                    $this->cell($w, $h, substr($s, $j, $sep - $j), 0, 2, '', false, $link);
                    $i = $sep + 1;
                }

                $sep = -1;
                $j = $i;
                $l = 0;
                if ($nl === 1) {
                    $this->x = $this->lMargin;
                    $w = $this->w - $this->rMargin - $this->x;
                    $wmax = ($w - 2 * $this->cMargin) * $this->fontSize / 1000;
                }

                $nl++;
            } else {
                $i++;
            }
        }

        if ($i !== $j) {
            $this->cell($l / 1000 * $this->fontSize, $h, substr($s, $j), 0, 0, '', false, $link);
        }
    }

    public function ln(?float $h = null): void
    {
        $this->x = $this->lMargin;
        $this->y += $h ?? $this->lasth;
    }

    public function image(
        string $file,
        ?float $x = null,
        ?float $y = null,
        float $w = 0,
        float $h = 0,
        string $type = '',
        int|string $link = '',
    ): void {
        $info = $this->imageHandler->addImage($file, $type);

        // Automatic width and height calculation
        if ($w <= 0.0 && $h <= 0.0) {
            $w = -96.0;
            $h = -96.0;
        }

        if ($w < 0.0) {
            $w = -$info['w'] * 72.0 / $w / $this->k;
        }

        if ($h < 0.0) {
            $h = -$info['h'] * 72.0 / $h / $this->k;
        }

        if ($w <= 0.0) {
            $w = $h * $info['w'] / $info['h'];
        }

        if ($h <= 0.0) {
            $h = $w * $info['h'] / $info['w'];
        }

        // Flowing mode
        if ($y === null) {
            if ($this->y + $h > $this->pageBreakTrigger && !$this->inHeader && !$this->inFooter && $this->acceptPageBreak()) {
                $x2 = $this->x;
                $this->addPage($this->curOrientation, $this->curPageSize, $this->curRotation);
                $this->x = $x2;
            }

            $y = $this->y;
            $this->y += $h;
        }

        if ($x === null) {
            $x = $this->x;
        }

        $this->out(sprintf(
            'q %.2F 0 0 %.2F %.2F %.2F cm /I%d Do Q',
            $w * $this->k,
            $h * $this->k,
            $x * $this->k,
            ($this->h - ($y + $h)) * $this->k,
            $info['i'],
        ));

        if ($link) {
            $this->link($x, $y, $w, $h, $link);
        }
    }

    public function getPageWidth(): float
    {
        return $this->w;
    }

    public function getPageHeight(): float
    {
        return $this->h;
    }

    public function getX(): float
    {
        return $this->x;
    }

    public function setX(float $x): void
    {
        $this->x = $x >= 0 ? $x : $this->w + $x;
    }

    public function getY(): float
    {
        return $this->y;
    }

    public function setY(float $y, bool $resetX = true): void
    {
        $this->y = $y >= 0 ? $y : $this->h + $y;
        if ($resetX) {
            $this->x = $this->lMargin;
        }
    }

    public function setXY(float $x, float $y): void
    {
        $this->setX($x);
        $this->setY($y, false);
    }

    public function output(
        string $dest = '',
        string $name = '',
        bool $isUTF8 = false,
    ): string {
        $this->close();

        if (strlen($name) === 1 && strlen($dest) !== 1) {
            $tmp = $dest;
            $dest = $name;
            $name = $tmp;
        }

        if ($dest === '') {
            $dest = 'I';
        }

        if ($name === '') {
            $name = 'doc.pdf';
        }

        $destination = OutputDestination::fromString($dest);
        return $this->outputHandler->output($this->buffer->getContent(), $destination, $name, $isUTF8);
    }

    private function beginPage(string|PageOrientation $orientation, string|array|PageSize $size, int $rotation): void
    {
        $this->pageManager->addPage();
        $this->state = 2;
        $this->x = $this->lMargin;
        $this->y = $this->tMargin;
        $this->fontFamily = '';

        // Check page size and orientation
        if ($orientation === '') {
            $orientation = $this->defOrientation;
        } elseif (is_string($orientation)) {
            $orientation = PageOrientation::fromString($orientation);
        }

        if ($size === '') {
            $size = $this->defPageSize;
        } elseif (is_string($size)) {
            $size = PageSize::fromString($size, $this->k);
        } elseif (is_array($size)) {
            $size = PageSize::fromArray($size, $this->k);
        }

        if ($orientation !== $this->curOrientation || !$size->equals($this->curPageSize)) {
            if ($orientation === PageOrientation::PORTRAIT) {
                $this->w = $size->getWidth();
                $this->h = $size->getHeight();
            } else {
                $this->w = $size->getHeight();
                $this->h = $size->getWidth();
            }

            $this->wPt = $this->w * $this->k;
            $this->hPt = $this->h * $this->k;
            $this->pageBreakTrigger = $this->h - $this->bMargin;
            $this->curOrientation = $orientation;
            $this->curPageSize = $size;
        }

        if ($orientation !== $this->defOrientation || !$size->equals($this->defPageSize)) {
            $this->pageManager->setPageInfo(
                $this->pageManager->getCurrentPage(),
                'size',
                [$this->wPt, $this->hPt],
            );
        }

        if ($rotation !== 0) {
            if ($rotation % 90 !== 0) {
                $this->error('Incorrect rotation value: ' . $rotation);
            }

            $this->pageManager->setPageInfo($this->pageManager->getCurrentPage(), 'rotation', $rotation);
        }

        $this->curRotation = $rotation;
    }

    private function endPage(): void
    {
        $this->state = 1;
    }

    private function endDoc(): void
    {
        $this->metadata->setCreationDate(time());

        // Replace alias in all pages
        if (!empty($this->aliasNbPages)) {
            $totalPages = $this->pageManager->getCurrentPage();
            for ($i = 1; $i <= $totalPages; $i++) {
                $this->pageManager->replaceInPage($i, $this->aliasNbPages, (string) $totalPages);
            }
        }

        $this->pdfStructure->build(
            $this->defOrientation,
            $this->defPageSize,
            $this->k,
            $this->zoomMode,
            $this->layoutMode,
            $this->aliasNbPages,
        );
        $this->state = 3;
    }

    private function out(string $s): void
    {
        if ($this->state === 2) {
            $this->pageManager->appendContent($this->pageManager->getCurrentPage(), $s);
        } elseif ($this->state === 0) {
            $this->error('No page has been added yet');
        } elseif ($this->state === 1) {
            $this->error('Invalid call');
        } elseif ($this->state === 3) {
            $this->error('The document is closed');
        }
    }

    private function doUnderline(float $x, float $y, string $txt): string
    {
        if ($this->currentFont === null) {
            return '';
        }

        $up = $this->currentFont['up'] ?? 0;
        $ut = $this->currentFont['ut'] ?? 0;
        $w = $this->getStringWidth($txt) + $this->ws * substr_count($txt, ' ');

        return sprintf(
            '%.2F %.2F %.2F %.2F re f',
            $x * $this->k,
            ($this->h - ($y - $up / 1000 * $this->fontSize)) * $this->k,
            $w * $this->k,
            -$ut / 1000 * $this->fontSizePt,
        );
    }
}
