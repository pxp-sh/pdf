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

namespace PXP\PDF\Fpdf\Structure;

use PXP\PDF\Fpdf\Buffer\Buffer;
use PXP\PDF\Fpdf\Enum\LayoutMode;
use PXP\PDF\Fpdf\Enum\PageOrientation;
use PXP\PDF\Fpdf\Enum\ZoomMode;
use PXP\PDF\Fpdf\Event\NullDispatcher;
use PXP\PDF\Fpdf\Exception\FpdfException;
use PXP\PDF\Fpdf\Font\FontManager;
use PXP\PDF\Fpdf\Image\ImageHandler;
use PXP\PDF\Fpdf\IO\FileReaderInterface;
use PXP\PDF\Fpdf\Link\LinkManager;
use PXP\PDF\Fpdf\Log\NullLogger;
use PXP\PDF\Fpdf\Metadata\Metadata;
use PXP\PDF\Fpdf\Page\PageManager;
use PXP\PDF\Fpdf\Text\TextRenderer;
use PXP\PDF\Fpdf\ValueObject\PageSize;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

final class PDFStructure
{
    private int $n = 2;
    private array $offsets = [];

    public function __construct(
        private Buffer $buffer,
        private PageManager $pageManager,
        private FontManager $fontManager,
        private ImageHandler $imageHandler,
        private LinkManager $linkManager,
        private Metadata $metadata,
        private TextRenderer $textRenderer,
        private FileReaderInterface $fileReader,
        private bool $compress = true,
        private bool $withAlpha = false,
        private string $pdfVersion = '1.3',
        ?LoggerInterface $logger = null,
        ?EventDispatcherInterface $dispatcher = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->dispatcher = $dispatcher ?? new NullDispatcher();
    }

    private LoggerInterface $logger;
    private EventDispatcherInterface $dispatcher;

    public function build(
        PageOrientation $defOrientation,
        PageSize $defPageSize,
        float $scaleFactor,
        string|float $zoomMode,
        LayoutMode $layoutMode,
        string $aliasNbPages = '',
    ): void {
        $startTime = microtime(true);
        $pageCount = $this->pageManager->getCurrentPage();

        $this->logger->info('PDF structure building started', [
            'page_count' => $pageCount,
            'orientation' => $defOrientation->value,
            'page_size' => ['width' => $defPageSize->getWidth(), 'height' => $defPageSize->getHeight()],
            'compress' => $this->compress,
            'pdf_version' => $this->pdfVersion,
        ]);

        $this->putHeader();
        $this->putPages($defOrientation, $defPageSize, $scaleFactor);
        $this->putResources();
        $this->putInfo();
        $this->putCatalog($zoomMode, $layoutMode, $aliasNbPages);
        $this->putXref();

        $duration = (microtime(true) - $startTime) * 1000;
        $this->logger->info('PDF structure building completed', [
            'page_count' => $pageCount,
            'duration_ms' => round($duration, 2),
            'buffer_size' => $this->buffer->getLength(),
        ]);
    }

    private function putHeader(): void
    {
        $this->logger->debug('Writing PDF header', [
            'version' => $this->pdfVersion,
        ]);
        $this->put('%PDF-' . $this->pdfVersion);
    }

    private function putPages(PageOrientation $defOrientation, PageSize $defPageSize, float $scaleFactor): void
    {
        $nb = $this->pageManager->getCurrentPage();
        $n = $this->n;
        $linkNumbers = [];

        $this->logger->debug('Writing pages', [
            'page_count' => $nb,
            'orientation' => $defOrientation->value,
        ]);


        for ($i = 1; $i <= $nb; $i++) {
            $pageN = ++$n;
            $n++;
            $this->pageManager->setPageInfo($i, 'n', $pageN);
            $pageLinks = $this->linkManager->getPageLinks($i);
            $linkNumbers[$i] = [];
            foreach ($pageLinks as $idx => $pl) {
                $linkNumbers[$i][$idx] = ++$n;
            }
        }


        for ($i = 1; $i <= $nb; $i++) {
            $this->putPage($i, $scaleFactor, $linkNumbers[$i] ?? []);
        }


        $this->newObj(1);
        $this->put('<</Type /Pages');
        $kids = '/Kids [';
        for ($i = 1; $i <= $nb; $i++) {
            $pageInfo = $this->pageManager->getPageInfo($i);
            $kids .= ($pageInfo['n'] ?? 0) . ' 0 R ';
        }

        $kids .= ']';
        $this->put($kids);
        $this->put('/Count ' . $nb);
        if ($defOrientation === PageOrientation::PORTRAIT) {
            $w = $defPageSize->getWidth();
            $h = $defPageSize->getHeight();
        } else {
            $w = $defPageSize->getHeight();
            $h = $defPageSize->getWidth();
        }

        $this->put(sprintf('/MediaBox [0 0 %.2F %.2F]', $w * $scaleFactor, $h * $scaleFactor));
        $this->put('>>');
        $this->put('endobj');
    }

    private function putPage(int $n, float $scaleFactor, array $linkNumbers = []): void
    {
        $this->logger->debug('Writing page object', [
            'page_number' => $n,
            'object_number' => $this->n + 1,
        ]);

        $this->newObj();
        $this->put('<</Type /Page');
        $this->put('/Parent 1 0 R');
        $pageInfo = $this->pageManager->getPageInfo($n);
        if (isset($pageInfo['size'])) {
            $this->put(sprintf('/MediaBox [0 0 %.2F %.2F]', $pageInfo['size'][0], $pageInfo['size'][1]));
            $this->logger->debug('Page MediaBox set', [
                'page_number' => $n,
                'size' => $pageInfo['size'],
            ]);
        }

        if (isset($pageInfo['rotation'])) {
            $this->put('/Rotate ' . $pageInfo['rotation']);
            $this->logger->debug('Page rotation set', [
                'page_number' => $n,
                'rotation' => $pageInfo['rotation'],
            ]);
        }

        $this->put('/Resources 2 0 R');
        $pageLinks = $this->linkManager->getPageLinks($n);
        if (!empty($pageLinks)) {
            $s = '/Annots [';
            foreach ($pageLinks as $idx => $pl) {
                $s .= ($linkNumbers[$idx] ?? 0) . ' 0 R ';
            }

            $s .= ']';
            $this->put($s);
        }

        if ($this->withAlpha) {
            $this->put('/Group <</Type /Group /S /Transparency /CS /DeviceRGB>>');
        }

        $contentObjN = $this->n + 1;
        $this->put('/Contents ' . $contentObjN . ' 0 R>>');
        $this->put('endobj');


        $content = $this->pageManager->getPageContent($n);
        $this->putStreamObject($content);


        $this->putLinks($n, $scaleFactor, $linkNumbers);
    }

    private function putLinks(int $n, float $scaleFactor, array $linkNumbers = []): void
    {
        $pageLinks = $this->linkManager->getPageLinks($n);
        $links = $this->linkManager->getAllLinks();

        foreach ($pageLinks as $idx => $pl) {
            $linkObjNum = $linkNumbers[$idx] ?? 0;
            if ($linkObjNum === 0) {
                continue;
            }

            $this->newObj();
            $rect = sprintf('%.2F %.2F %.2F %.2F', $pl[0], $pl[1], $pl[0] + $pl[2], $pl[1] - $pl[3]);
            $s = '<</Type /Annot /Subtype /Link /Rect [' . $rect . '] /Border [0 0 0] ';
            if (is_string($pl[4])) {
                $s .= '/A <</S /URI /URI ' . $this->textRenderer->textString($pl[4]) . '>>>>';
            } else {
                $l = $links[$pl[4]] ?? [0, 0];
                $targetPageInfo = $this->pageManager->getPageInfo($l[0]);
                if (isset($targetPageInfo['size'])) {
                    $h = $targetPageInfo['size'][1];
                } else {
                    $h = 0;
                }

                $targetPageN = $targetPageInfo['n'] ?? 0;
                $s .= sprintf('/Dest [%d 0 R /XYZ 0 %.2F null]>>', $targetPageN, $h - $l[1] * $scaleFactor);
            }

            $this->put($s);
            $this->put('endobj');
        }
    }

    private function putResources(): void
    {
        $this->putFonts();
        $this->putImages();


        $this->newObj(2);
        $this->put('<<');
        $this->putResourcedict();
        $this->put('>>');
        $this->put('endobj');
    }

    private function putFonts(): void
    {
        $fontFiles = $this->fontManager->getFontFiles();
        $fontCount = count($fontFiles);

        $this->logger->debug('Writing fonts', [
            'font_count' => $fontCount,
        ]);

        foreach ($fontFiles as $file => $info) {
            $this->logger->debug('Writing font file object', [
                'font_file' => $file,
                'object_number' => $this->n + 1,
            ]);

            $this->newObj();
            $fontFiles[$file]['n'] = $this->n;
            $font = $this->readFile($file);
            if ($font === null) {
                $this->logger->error('Font file not found', [
                    'font_file' => $file,
                ]);
                throw new FpdfException('Font file not found: ' . $file);
            }

            $compressed = str_ends_with($file, '.z');
            if (!$compressed && isset($info['length2'])) {
                $font = substr($font, 6, $info['length1']) . substr($font, 6 + $info['length1'] + 6, $info['length2']);
            }

            $this->put('<</Length ' . strlen($font));
            if ($compressed) {
                $this->put('/Filter /FlateDecode');
            }

            $this->put('/Length1 ' . $info['length1']);
            if (isset($info['length2'])) {
                $this->put('/Length2 ' . $info['length2'] . ' /Length3 0');
            }

            $this->put('>>');
            $this->putStream($font);
            $this->put('endobj');
        }

        $fonts = $this->fontManager->getAllFonts();
        $encodings = $this->fontManager->getEncodings();
        $cmaps = $this->fontManager->getCmaps();

        $this->logger->debug('Writing font objects', [
            'font_count' => count($fonts),
            'encoding_count' => count($encodings),
            'cmap_count' => count($cmaps),
        ]);

        foreach ($fonts as $k => $font) {
            $this->logger->debug('Writing font object', [
                'font_key' => $k,
                'font_name' => $font['name'] ?? 'unknown',
                'font_type' => $font['type'] ?? 'unknown',
                'object_number' => $this->n + 1,
            ]);

            if (isset($font['diff'])) {
                if (!isset($encodings[$font['enc']])) {
                    $this->newObj();
                    $this->put('<</Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences [' . $font['diff'] . ']>>');
                    $this->put('endobj');
                    $encodings[$font['enc']] = $this->n;
                    $this->fontManager->setEncoding($font['enc'], $this->n);
                }
            }


            if (isset($font['uv'])) {
                $cmapkey = $font['enc'] ?? $font['name'];
                if (!isset($cmaps[$cmapkey])) {
                    $cmap = $this->toUnicodeCmap($font['uv']);
                    $this->putStreamObject($cmap);
                    $cmaps[$cmapkey] = $this->n;
                    $this->fontManager->setCmap($cmapkey, $this->n);
                }
            }


            $fonts[$k]['n'] = $this->n + 1;

            $this->fontManager->setFontObjectNumber($k, $fonts[$k]['n']);
            $type = $font['type'];
            $name = $font['name'];
            if ($font['subsetted'] ?? false) {
                $name = 'AAAAAA+' . $name;
            }

            if ($type === 'Core') {
                $this->newObj();
                $this->put('<</Type /Font');
                $this->put('/BaseFont /' . $name);
                $this->put('/Subtype /Type1');
                if ($name !== 'Symbol' && $name !== 'ZapfDingbats') {
                    $this->put('/Encoding /WinAnsiEncoding');
                }

                if (isset($font['uv'])) {
                    $cmapkey = $font['enc'] ?? $font['name'];
                    $this->put('/ToUnicode ' . $cmaps[$cmapkey] . ' 0 R');
                }

                $this->put('>>');
                $this->put('endobj');
            } elseif ($type === 'Type1' || $type === 'TrueType') {
                $this->newObj();
                $this->put('<</Type /Font');
                $this->put('/BaseFont /' . $name);
                $this->put('/Subtype /' . $type);
                $this->put('/FirstChar 32 /LastChar 255');
                $this->put('/Widths ' . ($this->n + 1) . ' 0 R');
                $this->put('/FontDescriptor ' . ($this->n + 2) . ' 0 R');
                if (isset($font['diff'])) {
                    $this->put('/Encoding ' . $encodings[$font['enc']] . ' 0 R');
                } else {
                    $this->put('/Encoding /WinAnsiEncoding');
                }

                if (isset($font['uv'])) {
                    $cmapkey = $font['enc'] ?? $font['name'];
                    $this->put('/ToUnicode ' . $cmaps[$cmapkey] . ' 0 R');
                }

                $this->put('>>');
                $this->put('endobj');


                $this->newObj();
                $cw = $font['cw'];
                $s = '[';
                for ($i = 32; $i <= 255; $i++) {
                    $s .= ($cw[chr($i)] ?? 0) . ' ';
                }

                $this->put($s . ']');
                $this->put('endobj');


                $this->newObj();
                $s = '<</Type /FontDescriptor /FontName /' . $name;
                foreach ($font['desc'] as $k => $v) {
                    $s .= ' /' . $k . ' ' . $v;
                }

                if (!empty($font['file'])) {
                    $s .= ' /FontFile' . ($type === 'Type1' ? '' : '2') . ' ' . $fontFiles[$font['file']]['n'] . ' 0 R';
                }

                $this->put($s . '>>');
                $this->put('endobj');
            }
        }
    }

    private function putImages(): void
    {
        $images = $this->imageHandler->getAllImages();
        $imageCount = count($images);

        $this->logger->debug('Writing images', [
            'image_count' => $imageCount,
        ]);

        foreach (array_keys($images) as $file) {
            $this->logger->debug('Writing image object', [
                'image_file' => $file,
                'object_number' => $this->n + 1,
                'dimensions' => ['w' => $images[$file]['w'] ?? 0, 'h' => $images[$file]['h'] ?? 0],
            ]);
            $this->putImage($file, $images[$file]);
            unset($images[$file]['data'], $images[$file]['smask']);
        }
    }

    private function putImage(?string $file, array &$info): void
    {
        $this->newObj();
        $info['n'] = $this->n;
        if ($file !== null) {
            $this->imageHandler->setImageObjectNumber($file, $this->n);
        }

        $this->put('<</Type /XObject');
        $this->put('/Subtype /Image');
        $this->put('/Width ' . $info['w']);
        $this->put('/Height ' . $info['h']);
        if ($info['cs'] === 'Indexed') {
            $this->put('/ColorSpace [/Indexed /DeviceRGB ' . (strlen($info['pal']) / 3 - 1) . ' ' . ($this->n + 1) . ' 0 R]');
        } else {
            $this->put('/ColorSpace /' . $info['cs']);
            if ($info['cs'] === 'DeviceCMYK') {
                $this->put('/Decode [1 0 1 0 1 0 1 0]');
            }
        }

        $this->put('/BitsPerComponent ' . $info['bpc']);
        if (isset($info['f'])) {
            $this->put('/Filter /' . $info['f']);
        }

        if (isset($info['dp'])) {
            $this->put('/DecodeParms <<' . $info['dp'] . '>>');
        }

        if (isset($info['trns']) && is_array($info['trns'])) {
            $trns = '';
            for ($i = 0; $i < count($info['trns']); $i++) {
                $trns .= $info['trns'][$i] . ' ' . $info['trns'][$i] . ' ';
            }

            $this->put('/Mask [' . $trns . ']');
        }

        if (isset($info['smask'])) {
            $this->put('/SMask ' . ($this->n + 1) . ' 0 R');
        }

        $this->put('/Length ' . strlen($info['data']) . '>>');
        $this->putStream($info['data']);
        $this->put('endobj');


        if (isset($info['smask'])) {
            $dp = '/Predictor 15 /Colors 1 /BitsPerComponent 8 /Columns ' . $info['w'];
            $smask = [
                'w' => $info['w'],
                'h' => $info['h'],
                'cs' => 'DeviceGray',
                'bpc' => 8,
                'f' => $info['f'],
                'dp' => $dp,
                'data' => $info['smask'],
            ];
            $this->putImage(null, $smask);
        }


        if ($info['cs'] === 'Indexed') {
            $this->putStreamObject($info['pal']);
        }
    }

    private function putResourcedict(): void
    {
        $this->put('/ProcSet [/PDF /Text /ImageB /ImageC /ImageI]');
        $this->put('/Font <<');
        $fonts = $this->fontManager->getAllFonts();
        foreach ($fonts as $font) {
            $this->put('/F' . $font['i'] . ' ' . ($font['n'] ?? 0) . ' 0 R');
        }

        $this->put('>>');
        $this->put('/XObject <<');
        $images = $this->imageHandler->getAllImages();
        foreach ($images as $image) {
            $this->put('/I' . $image['i'] . ' ' . ($image['n'] ?? 0) . ' 0 R');
        }

        $this->put('>>');
    }

    private function putInfo(): void
    {
        $this->newObj();
        $this->put('<<');
        foreach ($this->metadata->getAll() as $key => $value) {
            $this->put('/' . $key . ' ' . $this->textRenderer->textString($value));
        }

        $this->put('>>');
        $this->put('endobj');
    }

    private function putCatalog(string|float $zoomMode, LayoutMode $layoutMode, string $aliasNbPages): void
    {
        $firstPageInfo = $this->pageManager->getPageInfo(1);
        $n = $firstPageInfo['n'] ?? 0;
        $this->newObj();
        $this->put('<<');
        $this->put('/Type /Catalog');
        $this->put('/Pages 1 0 R');
        if ($zoomMode === 'fullpage') {
            $this->put('/OpenAction [' . $n . ' 0 R /Fit]');
        } elseif ($zoomMode === 'fullwidth') {
            $this->put('/OpenAction [' . $n . ' 0 R /FitH null]');
        } elseif ($zoomMode === 'real') {
            $this->put('/OpenAction [' . $n . ' 0 R /XYZ null null 1]');
        } elseif (is_float($zoomMode)) {
            $this->put('/OpenAction [' . $n . ' 0 R /XYZ null null ' . sprintf('%.2F', $zoomMode / 100) . ']');
        }

        if ($layoutMode === LayoutMode::SINGLE) {
            $this->put('/PageLayout /SinglePage');
        } elseif ($layoutMode === LayoutMode::CONTINUOUS) {
            $this->put('/PageLayout /OneColumn');
        } elseif ($layoutMode === LayoutMode::TWO) {
            $this->put('/PageLayout /TwoColumnLeft');
        }

        $this->put('>>');
        $this->put('endobj');
    }

    private function putXref(): void
    {
        $offset = $this->buffer->getLength();
        $objectCount = $this->n + 1;

        $this->logger->debug('Writing xref table', [
            'object_count' => $objectCount,
            'xref_offset' => $offset,
        ]);

        $this->put('xref');
        $this->put('0 ' . $objectCount);
        $this->put('0000000000 65535 f ');
        for ($i = 1; $i <= $this->n; $i++) {
            $this->put(sprintf('%010d 00000 n ', $this->offsets[$i]));
        }

        $this->logger->debug('Writing trailer', [
            'root_object' => $this->n,
            'info_object' => $this->n - 1,
        ]);

        $this->put('trailer');
        $this->put('<<');
        $this->put('/Size ' . $objectCount);
        $this->put('/Root ' . $this->n . ' 0 R');
        $this->put('/Info ' . ($this->n - 1) . ' 0 R');
        $this->put('>>');
        $this->put('startxref');
        $this->put((string) $offset);
        $this->put('%%EOF');

        $this->logger->debug('Xref table and trailer written', [
            'xref_offset' => $offset,
            'total_objects' => $objectCount,
        ]);
    }

    private function newObj(?int $n = null): void
    {
        if ($n === null) {
            $n = ++$this->n;
        }

        $this->offsets[$n] = $this->buffer->getLength();
        $this->put($n . ' 0 obj');
    }

    private function put(string $s): void
    {
        $this->buffer->append($s);
    }

    private function putStream(string $data): void
    {
        $this->put('stream');
        $this->buffer->append($data);
        $this->put('endstream');
    }

    private function putStreamObject(string $data): void
    {
        if ($this->compress) {
            $entries = '/Filter /FlateDecode ';
            $data = gzcompress($data);
        } else {
            $entries = '';
        }

        $entries .= '/Length ' . strlen($data);
        $this->newObj();
        $this->put('<<' . $entries . '>>');
        $this->putStream($data);
        $this->put('endobj');
    }

    private function toUnicodeCmap(array $uv): string
    {
        $ranges = '';
        $nbr = 0;
        $chars = '';
        $nbc = 0;
        foreach ($uv as $c => $v) {
            if (is_array($v)) {
                $ranges .= sprintf("<%02X> <%02X> <%04X>\n", $c, $c + $v[1] - 1, $v[0]);
                $nbr++;
            } else {
                $chars .= sprintf("<%02X> <%04X>\n", $c, $v);
                $nbc++;
            }
        }

        $s = "/CIDInit /ProcSet findresource begin\n";
        $s .= "12 dict begin\n";
        $s .= "begincmap\n";
        $s .= "/CIDSystemInfo\n";
        $s .= "<</Registry (Adobe)\n";
        $s .= "/Ordering (UCS)\n";
        $s .= "/Supplement 0\n";
        $s .= ">> def\n";
        $s .= "/CMapName /Adobe-Identity-UCS def\n";
        $s .= "/CMapType 2 def\n";
        $s .= "1 begincodespacerange\n";
        $s .= "<00> <FF>\n";
        $s .= "endcodespacerange\n";
        if ($nbr > 0) {
            $s .= "$nbr beginbfrange\n";
            $s .= $ranges;
            $s .= "endbfrange\n";
        }

        if ($nbc > 0) {
            $s .= "$nbc beginbfchar\n";
            $s .= $chars;
            $s .= "endbfchar\n";
        }

        $s .= "endcmap\n";
        $s .= "CMapName currentdict /CMap defineresource pop\n";
        $s .= "end\n";
        $s .= "end";

        return $s;
    }

    private function readFile(string $file): ?string
    {
        try {
            return $this->fileReader->readFile($file);
        } catch (FpdfException $e) {
            return null;
        }
    }

    public function setWithAlpha(bool $withAlpha): void
    {
        $this->withAlpha = $withAlpha;
    }

    public function setPdfVersion(string $version): void
    {
        $this->pdfVersion = $version;
    }
}
