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

namespace PXP\PDF\Fpdf\Structure;

use PXP\PDF\Fpdf\Buffer\Buffer;
use PXP\PDF\Fpdf\Enum\LayoutMode;
use PXP\PDF\Fpdf\Enum\PageOrientation;
use PXP\PDF\Fpdf\Enum\ZoomMode;
use PXP\PDF\Fpdf\Exception\FpdfException;
use PXP\PDF\Fpdf\Font\FontManager;
use PXP\PDF\Fpdf\Image\ImageHandler;
use PXP\PDF\Fpdf\Link\LinkManager;
use PXP\PDF\Fpdf\Metadata\Metadata;
use PXP\PDF\Fpdf\Page\PageManager;
use PXP\PDF\Fpdf\Text\TextRenderer;
use PXP\PDF\Fpdf\ValueObject\PageSize;

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
        private bool $compress = true,
        private bool $withAlpha = false,
        private string $pdfVersion = '1.3',
    ) {
    }

    public function build(
        PageOrientation $defOrientation,
        PageSize $defPageSize,
        float $scaleFactor,
        string|float $zoomMode,
        LayoutMode $layoutMode,
        string $aliasNbPages = '',
    ): void {
        $this->putHeader();
        $this->putPages($defOrientation, $defPageSize, $scaleFactor);
        $this->putResources();
        $this->putInfo();
        $this->putCatalog($zoomMode, $layoutMode, $aliasNbPages);
        $this->putXref();
    }

    private function putHeader(): void
    {
        $this->put('%PDF-' . $this->pdfVersion);
    }

    private function putPages(PageOrientation $defOrientation, PageSize $defPageSize, float $scaleFactor): void
    {
        $nb = $this->pageManager->getCurrentPage();
        $n = $this->n;
        $linkNumbers = []; // [pageNum][linkIndex] => objectNumber

        // First pass: calculate all object numbers
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

        // Second pass: write pages and links
        for ($i = 1; $i <= $nb; $i++) {
            $this->putPage($i, $scaleFactor, $linkNumbers[$i] ?? []);
        }

        // Pages root
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
        $this->newObj();
        $this->put('<</Type /Page');
        $this->put('/Parent 1 0 R');
        $pageInfo = $this->pageManager->getPageInfo($n);
        if (isset($pageInfo['size'])) {
            $this->put(sprintf('/MediaBox [0 0 %.2F %.2F]', $pageInfo['size'][0], $pageInfo['size'][1]));
        }

        if (isset($pageInfo['rotation'])) {
            $this->put('/Rotate ' . $pageInfo['rotation']);
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

        // Page content
        $content = $this->pageManager->getPageContent($n);
        $this->putStreamObject($content);

        // Link annotations
        $this->putLinks($n, $scaleFactor, $linkNumbers);
    }

    private function putLinks(int $n, float $scaleFactor, array $linkNumbers = []): void
    {
        $pageLinks = $this->linkManager->getPageLinks($n);
        $links = $this->linkManager->getAllLinks();

        foreach ($pageLinks as $idx => $pl) {
            $linkObjNum = $linkNumbers[$idx] ?? 0;
            if ($linkObjNum === 0) {
                continue; // Skip if no object number assigned
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
                    $h = 0; // Will be set by caller
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

        // Resource dictionary
        $this->newObj(2);
        $this->put('<<');
        $this->putResourcedict();
        $this->put('>>');
        $this->put('endobj');
    }

    private function putFonts(): void
    {
        $fontFiles = $this->fontManager->getFontFiles();
        foreach ($fontFiles as $file => $info) {
            $this->newObj();
            $fontFiles[$file]['n'] = $this->n;
            $font = $this->readFile($file);
            if ($font === null) {
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

        foreach ($fonts as $k => $font) {
            // Encoding
            if (isset($font['diff'])) {
                if (!isset($encodings[$font['enc']])) {
                    $this->newObj();
                    $this->put('<</Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences [' . $font['diff'] . ']>>');
                    $this->put('endobj');
                    $encodings[$font['enc']] = $this->n;
                    $this->fontManager->setEncoding($font['enc'], $this->n);
                }
            }

            // ToUnicode CMap
            if (isset($font['uv'])) {
                $cmapkey = $font['enc'] ?? $font['name'];
                if (!isset($cmaps[$cmapkey])) {
                    $cmap = $this->toUnicodeCmap($font['uv']);
                    $this->putStreamObject($cmap);
                    $cmaps[$cmapkey] = $this->n;
                    $this->fontManager->setCmap($cmapkey, $this->n);
                }
            }

            // Font object
            $fonts[$k]['n'] = $this->n + 1;
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

                // Widths
                $this->newObj();
                $cw = $font['cw'];
                $s = '[';
                for ($i = 32; $i <= 255; $i++) {
                    $s .= ($cw[chr($i)] ?? 0) . ' ';
                }

                $this->put($s . ']');
                $this->put('endobj');

                // Descriptor
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
        foreach (array_keys($images) as $file) {
            $this->putImage($images[$file]);
            unset($images[$file]['data'], $images[$file]['smask']);
        }
    }

    private function putImage(array &$info): void
    {
        $this->newObj();
        $info['n'] = $this->n;
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

        // Soft mask
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
            $this->putImage($smask);
        }

        // Palette
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
            $this->put('/F' . $font['i'] . ' ' . $font['n'] . ' 0 R');
        }

        $this->put('>>');
        $this->put('/XObject <<');
        $images = $this->imageHandler->getAllImages();
        foreach ($images as $image) {
            $this->put('/I' . $image['i'] . ' ' . $image['n'] . ' 0 R');
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
        $this->put('xref');
        $this->put('0 ' . ($this->n + 1));
        $this->put('0000000000 65535 f ');
        for ($i = 1; $i <= $this->n; $i++) {
            $this->put(sprintf('%010d 00000 n ', $this->offsets[$i]));
        }

        // Trailer
        $this->put('trailer');
        $this->put('<<');
        $this->put('/Size ' . ($this->n + 1));
        $this->put('/Root ' . $this->n . ' 0 R');
        $this->put('/Info ' . ($this->n - 1) . ' 0 R');
        $this->put('>>');
        $this->put('startxref');
        $this->put((string) $offset);
        $this->put('%%EOF');
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
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $contents = stream_get_contents($handle);
            return $contents !== false ? $contents : null;
        } finally {
            fclose($handle);
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
