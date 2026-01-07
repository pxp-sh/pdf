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
namespace PXP\PDF\Fpdf\Core;

use function defined;
use function dirname;
use function function_exists;
use function is_array;
use function is_string;
use function sprintf;
use function str_contains;
use function str_replace;
use function strlen;
use function strtolower;
use function strtoupper;
use function substr;
use function substr_count;
use function time;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use PXP\PDF\Fpdf\Core\Structure\PDFStructure;
use PXP\PDF\Fpdf\Events\Event\NullDispatcher;
use PXP\PDF\Fpdf\Events\Log\NullLogger;
use PXP\PDF\Fpdf\Exceptions\Exception\FpdfException;
use PXP\PDF\Fpdf\Features\Link\LinkManager;
use PXP\PDF\Fpdf\Features\Metadata\Metadata;
use PXP\PDF\Fpdf\Features\Splitter\PDFMerger;
use PXP\PDF\Fpdf\Features\Splitter\PDFSplitter;
use PXP\PDF\Fpdf\IO\FileIO;
use PXP\PDF\Fpdf\IO\FileIOInterface;
use PXP\PDF\Fpdf\IO\OutputHandler;
use PXP\PDF\Fpdf\Rendering\Color\ColorManager;
use PXP\PDF\Fpdf\Rendering\Font\FontManager;
use PXP\PDF\Fpdf\Rendering\Image\ImageHandler;
use PXP\PDF\Fpdf\Rendering\Page\PageManager;
use PXP\PDF\Fpdf\Rendering\Text\TextRenderer;
use PXP\PDF\Fpdf\Utils\Buffer\Buffer;
use PXP\PDF\Fpdf\Utils\Cache\NullCache;
use PXP\PDF\Fpdf\Utils\Enum\LayoutMode;
use PXP\PDF\Fpdf\Utils\Enum\OutputDestination;
use PXP\PDF\Fpdf\Utils\Enum\PageOrientation;
use PXP\PDF\Fpdf\Utils\Enum\Unit;
use PXP\PDF\Fpdf\Utils\Enum\ZoomMode;
use PXP\PDF\Fpdf\Utils\ValueObject\PageSize;

class FPDF
{
    public const VERSION = '1.86-pxp-sh';
    private int $state   = 0;
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
    private string $fontFamily  = '';
    private string $fontStyle   = '';
    private bool $underline     = false;
    private ?array $currentFont = null;
    private float $fontSizePt   = 12;
    private float $fontSize;
    private float $ws = 0;
    private bool $autoPageBreak;
    private float $pageBreakTrigger;
    private bool $inHeader         = false;
    private bool $inFooter         = false;
    private string $aliasNbPages   = '';
    private float|string $zoomMode = 'default';
    private LayoutMode $layoutMode = LayoutMode::DEFAULT;
    private bool $compress;
    private bool $withAlpha    = false;
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
    private LoggerInterface $logger;
    private CacheItemPoolInterface $cache;
    private EventDispatcherInterface $dispatcher;

    /**
     * Split a PDF file into individual page files.
     *
     * @param string                        $pdfFilePath     Path to the PDF file to split
     * @param string                        $outputDir       Directory where split PDFs will be saved
     * @param null|string                   $filenamePattern Pattern for output filenames (use %d for page number, default: "page_%d.pdf")
     * @param null|LoggerInterface          $logger          Optional logger instance
     * @param null|CacheItemPoolInterface   $cache           Optional cache instance
     * @param null|EventDispatcherInterface $dispatcher      Optional event dispatcher instance
     *
     * @throws FpdfException
     *
     * @return array<string> Array of generated file paths
     */
    public static function splitPdf(
        string $pdfFilePath,
        string $outputDir,
        ?string $filenamePattern = null,
        ?LoggerInterface $logger = null,
        ?CacheItemPoolInterface $cache = null,
        ?EventDispatcherInterface $dispatcher = null,
    ): array {
        $fileIO   = new FileIO($logger);
        $splitter = new PDFSplitter($pdfFilePath, $fileIO, $logger, $dispatcher, $cache);

        return $splitter->splitByPage($outputDir, $filenamePattern);
    }

    /**
     * Extract a single page from a PDF file.
     *
     * @param string                        $pdfFilePath Path to the PDF file
     * @param int                           $pageNumber  Page number to extract (1-based)
     * @param string                        $outputPath  Path where the single-page PDF will be saved
     * @param null|LoggerInterface          $logger      Optional logger instance
     * @param null|CacheItemPoolInterface   $cache       Optional cache instance
     * @param null|EventDispatcherInterface $dispatcher  Optional event dispatcher instance
     *
     * @throws FpdfException
     */
    public static function extractPage(
        string $pdfFilePath,
        int $pageNumber,
        string $outputPath,
        ?LoggerInterface $logger = null,
        ?CacheItemPoolInterface $cache = null,
        ?EventDispatcherInterface $dispatcher = null,
    ): void {
        $fileIO   = new FileIO($logger);
        $splitter = new PDFSplitter($pdfFilePath, $fileIO, $logger, $dispatcher, $cache);
        $splitter->extractPage($pageNumber, $outputPath);
    }

    /**
     * Merge multiple PDF files into a single PDF.
     *
     * @param array<string>                 $pdfFilePaths Array of paths to PDF files to merge
     * @param string                        $outputPath   Path where the merged PDF will be saved
     * @param null|LoggerInterface          $logger       Optional logger instance
     * @param null|CacheItemPoolInterface   $cache        Optional cache instance
     * @param null|EventDispatcherInterface $dispatcher   Optional event dispatcher instance
     *
     * @throws FpdfException
     */
    public static function mergePdf(
        array $pdfFilePaths,
        string $outputPath,
        ?LoggerInterface $logger = null,
        ?CacheItemPoolInterface $cache = null,
        ?EventDispatcherInterface $dispatcher = null,
    ): void {
        $fileIO = new FileIO($logger);
        $merger = new PDFMerger($fileIO, $logger, $dispatcher, $cache);
        $merger->merge($pdfFilePaths, $outputPath);
    }

    public function __construct(
        PageOrientation|string $orientation = 'P',
        string|Unit $unit = 'mm',
        array|PageSize|string $size = 'A4',
        ?FileIOInterface $fileIO = null,
        ?LoggerInterface $logger = null,
        ?CacheItemPoolInterface $cache = null,
        ?EventDispatcherInterface $dispatcher = null,
    ) {
        $this->logger     = $logger ?? new NullLogger;
        $this->cache      = $cache ?? new NullCache;
        $this->dispatcher = $dispatcher ?? new NullDispatcher;

        if ($fileIO === null) {
            $fileIO = new FileIO($this->logger);
        }

        $this->logger->debug('FPDF initialization started', [
            'orientation' => is_string($orientation) ? $orientation : $orientation->value,
            'unit'        => is_string($unit) ? $unit : $unit->value,
            'size'        => is_string($size) ? $size : (is_array($size) ? 'array' : 'PageSize'),
        ]);

        $this->buffer       = new Buffer;
        $this->textRenderer = new TextRenderer;
        $this->colorManager = new ColorManager;
        $this->linkManager  = new LinkManager;
        $this->imageHandler = new ImageHandler($fileIO, $fileIO, $this->logger, $this->cache);
        $this->pageManager  = new PageManager($fileIO, $this->logger, $this->dispatcher);

        $fontPath          = defined('FPDF_FONTPATH') ? FPDF_FONTPATH : dirname(__DIR__) . '/IO/font/';
        $this->fontManager = new FontManager($fontPath, 500, $this->logger, $this->cache);

        if (is_string($unit)) {
            $unit = Unit::fromString($unit);
        }

        $this->k = $unit->getScaleFactor();

        if (is_string($size)) {
            $pageSize = PageSize::fromString($size, $this->k);
        } elseif (is_array($size)) {
            $pageSize = PageSize::fromArray($size, $this->k);
        } else {
            $pageSize = $size;
        }

        $this->defPageSize = $pageSize;
        $this->curPageSize = $pageSize;

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

        $this->curRotation = 0;

        $margin = 28.35 / $this->k;
        $this->setMargins($margin, $margin);

        $this->cMargin = $margin / 10;

        $this->lineWidth = 0.567 / $this->k;

        $this->setAutoPageBreak(true, 2 * $margin);

        $this->setDisplayMode('default');

        $this->setCompression(true);

        $this->metadata = new Metadata('FPDF ' . self::VERSION);

        $this->pdfStructure = new PDFStructure(
            $this->buffer,
            $this->pageManager,
            $this->fontManager,
            $this->imageHandler,
            $this->linkManager,
            $this->metadata,
            $this->textRenderer,
            $fileIO,
            $this->compress,
            $this->withAlpha,
            $this->pdfVersion,
            $this->logger,
            $this->dispatcher,
        );

        $this->outputHandler = new OutputHandler($this->textRenderer, $fileIO);

        $this->logger->info('FPDF initialized', [
            'orientation' => $this->defOrientation->value,
            'page_size'   => ['width' => $this->w, 'height' => $this->h],
            'unit'        => $unit instanceof Unit ? $unit->value : Unit::fromString($unit)->value,
            'compress'    => $this->compress,
            'pdf_version' => $this->pdfVersion,
        ]);
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
        $this->autoPageBreak    = $auto;
        $this->bMargin          = $margin;
        $this->pageBreakTrigger = $this->h - $margin;
    }

    public function setDisplayMode(float|string $zoom, LayoutMode|string $layout = 'default'): void
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
            $this->logger->debug('Document already closed');

            return;
        }

        $this->logger->info('Closing PDF document', [
            'current_page' => $this->pageManager->getCurrentPage(),
        ]);

        if ($this->pageManager->getCurrentPage() === 0) {
            $this->addPage();
        }

        $this->inFooter = true;
        $this->footer();
        $this->inFooter = false;

        $this->endPage();

        $this->endDoc();

        $this->logger->info('PDF document closed', [
            'total_pages' => $this->pageManager->getCurrentPage(),
        ]);
    }

    public function addPage(
        PageOrientation|string $orientation = '',
        array|PageSize|string $size = '',
        int $rotation = 0,
    ): void {
        if ($this->state === 3) {
            $this->error('The document is closed');
        }

        $this->logger->debug('Adding page', [
            'current_page' => $this->pageManager->getCurrentPage(),
            'orientation'  => is_string($orientation) ? $orientation : ($orientation === '' ? 'default' : $orientation->value),
            'size'         => is_string($size) ? $size : (is_array($size) ? 'array' : ($size === '' ? 'default' : 'PageSize')),
            'rotation'     => $rotation,
        ]);

        $family   = $this->fontFamily;
        $style    = $this->fontStyle . ($this->underline ? 'U' : '');
        $fontSize = $this->fontSizePt;
        $lw       = $this->lineWidth;
        $dc       = $this->colorManager->getDrawColor();
        $fc       = $this->colorManager->getFillColor();
        $tc       = $this->colorManager->getTextColor();
        $cf       = $this->colorManager->hasColorFlag();

        if ($this->pageManager->getCurrentPage() > 0) {
            $this->inFooter = true;
            $this->footer();
            $this->inFooter = false;

            $this->endPage();
        }

        $this->beginPage($orientation, $size, $rotation);

        $this->out('2 J');

        $this->lineWidth = $lw;
        $this->out(sprintf('%.2F w', $lw * $this->k));

        if ($family) {
            $this->setFont($family, $style, $fontSize);
        }

        $this->colorManager->setDrawColor(0, null, null);

        if ($dc !== '0 G') {
            $this->out($dc);
        }

        $this->colorManager->setFillColor(0, null, null);

        if ($fc !== '0 g') {
            $this->out($fc);
        }

        $this->colorManager->setTextColor(0, null, null);

        $this->inHeader = true;
        $this->header();
        $this->inHeader = false;

        if ($this->lineWidth !== $lw) {
            $this->lineWidth = $lw;
            $this->out(sprintf('%.2F w', $lw * $this->k));
        }

        if ($family) {
            $this->setFont($family, $style, $fontSize);
        }

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
    }

    public function footer(): void
    {
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
        $this->logger->debug('Adding font', [
            'family' => $family,
            'style'  => $style,
            'file'   => $file,
            'dir'    => $dir,
        ]);
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
            $style           = str_replace('U', '', $style);
        } else {
            $this->underline = false;
        }

        if ($style === 'IB') {
            $style = 'BI';
        }

        if ($size <= 0.0) {
            $size = $this->fontSizePt;
        }

        $this->logger->debug('Setting font', [
            'family' => $family,
            'style'  => $style,
            'size'   => $size,
        ]);

        if ($this->fontFamily === $family && $this->fontStyle === $style && $this->fontSizePt === $size) {
            $this->logger->debug('Font unchanged, skipping', [
                'family' => $family,
                'style'  => $style,
                'size'   => $size,
            ]);

            return;
        }

        $font = $this->fontManager->getFont($family, $style);

        if ($font === null) {
            $this->error('Undefined font: ' . $family . ' ' . $style);
        }

        $this->fontFamily  = $family;
        $this->fontStyle   = $style;
        $this->fontSizePt  = $size;
        $this->fontSize    = $size / $this->k;
        $this->currentFont = $font;

        $this->logger->debug('Font set', [
            'family'     => $family,
            'style'      => $style,
            'size'       => $size,
            'font_index' => $font['i'] ?? 0,
        ]);

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
        $this->fontSize   = $size / $this->k;

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
            $x  = $this->x;
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
            $s  = sprintf(
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
        $s    = str_replace("\r", '', $txt);
        $nb   = strlen($s);

        if ($nb > 0 && $s[$nb - 1] === "\n") {
            $nb--;
        }

        $b  = 0;
        $b2 = '';

        if ($border) {
            if ($border === 1) {
                $border = 'LTRB';
                $b      = 'LRT';
                $b2     = 'LR';
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
        $i   = 0;
        $j   = 0;
        $l   = 0;
        $ns  = 0;
        $nl  = 1;
        $ls  = 0.0;

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
                $j   = $i;
                $l   = 0;
                $ns  = 0;
                $nl++;

                if ($border && $nl === 2) {
                    $b = $b2;
                }

                continue;
            }

            if ($c === ' ') {
                $sep = $i;
                $ls  = $l;
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
                $j   = $i;
                $l   = 0;
                $ns  = 0;
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

        $cw   = $this->currentFont['cw'];
        $w    = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->fontSize;
        $s    = str_replace("\r", '', $txt);
        $nb   = strlen($s);
        $sep  = -1;
        $i    = 0;
        $j    = 0;
        $l    = 0;
        $nl   = 1;

        while ($i < $nb) {
            $c = $s[$i];

            if ($c === "\n") {
                $this->cell($w, $h, substr($s, $j, $i - $j), 0, 2, '', false, $link);
                $i++;
                $sep = -1;
                $j   = $i;
                $l   = 0;

                if ($nl === 1) {
                    $this->x = $this->lMargin;
                    $w       = $this->w - $this->rMargin - $this->x;
                    $wmax    = ($w - 2 * $this->cMargin) * 1000 / $this->fontSize;
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
                        $w    = $this->w - $this->rMargin - $this->x;
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
                $j   = $i;
                $l   = 0;

                if ($nl === 1) {
                    $this->x = $this->lMargin;
                    $w       = $this->w - $this->rMargin - $this->x;
                    $wmax    = ($w - 2 * $this->cMargin) * $this->fontSize / 1000;
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
        $this->logger->debug('Adding image', [
            'file'     => $file,
            'type'     => $type ?: 'auto-detect',
            'position' => ['x' => $x, 'y' => $y],
            'size'     => ['w' => $w, 'h' => $h],
        ]);

        $info = $this->imageHandler->addImage($file, $type);

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
        $this->logger->info('PDF output started', [
            'destination' => $dest ?: 'I',
            'name'        => $name ?: 'doc.pdf',
            'is_utf8'     => $isUTF8,
        ]);

        $this->close();

        if (strlen($name) === 1 && strlen($dest) !== 1) {
            $tmp  = $dest;
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
        $result      = $this->outputHandler->output($this->buffer->getContent(), $destination, $name, $isUTF8);

        $this->logger->info('PDF output completed', [
            'destination'   => $dest,
            'name'          => $name,
            'result_length' => strlen($result),
        ]);

        // Clean up temporary page files after PDF generation
        $this->pageManager->cleanup();

        return $result;
    }

    private function beginPage(PageOrientation|string $orientation, array|PageSize|string $size, int $rotation): void
    {
        $this->pageManager->addPage();
        $this->state      = 2;
        $this->x          = $this->lMargin;
        $this->y          = $this->tMargin;
        $this->fontFamily = '';

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

            $this->wPt              = $this->w * $this->k;
            $this->hPt              = $this->h * $this->k;
            $this->pageBreakTrigger = $this->h - $this->bMargin;
            $this->curOrientation   = $orientation;
            $this->curPageSize      = $size;
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
        // Finalize current page to write it to temp file and free memory
        $this->pageManager->finalizeCurrentPage();
        $this->state = 1;
    }

    private function endDoc(): void
    {
        $this->metadata->setCreationDate(time());

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
        $w  = $this->getStringWidth($txt) + $this->ws * substr_count($txt, ' ');

        return sprintf(
            '%.2F %.2F %.2F %.2F re f',
            $x * $this->k,
            ($this->h - ($y - $up / 1000 * $this->fontSize)) * $this->k,
            $w * $this->k,
            -$ut / 1000 * $this->fontSizePt,
        );
    }
}
