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

namespace PXP\PDF\Fpdf\Image\Parser;

use PXP\PDF\Fpdf\Exception\FpdfException;
use PXP\PDF\Fpdf\IO\FileReaderInterface;
use PXP\PDF\Fpdf\IO\StreamFactoryInterface;

final class PngParser implements ImageParserInterface
{
    public function __construct(
        private FileReaderInterface $fileReader,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    public function parse(string $file): array
    {


        if (function_exists('imagecreatefrompng')) {
            $im = @imagecreatefrompng($file);
            if ($im !== false) {
                $w = imagesx($im);
                $h = imagesy($im);
                $trueColor = function_exists('imageistruecolor') ? imageistruecolor($im) : true;
                $colors = $trueColor ? 3 : 1;
                $bpc = 8;

                $raw = '';
                $alphaRaw = '';
                $hasAlpha = false;
                for ($y = 0; $y < $h; $y++) {

                    $raw .= chr(0);
                    $alphaRaw .= chr(0);
                    for ($x = 0; $x < $w; $x++) {
                        $rgba = imagecolorat($im, $x, $y);
                        $r = ($rgba >> 16) & 0xFF;
                        $g = ($rgba >> 8) & 0xFF;
                        $b = $rgba & 0xFF;
                        if ($trueColor) {
                            $raw .= chr($r) . chr($g) . chr($b);
                        } else {
                            $raw .= chr($r);
                        }


                        $a7 = ($rgba >> 24) & 0x7F;
                        $a8 = (int) round((127 - $a7) * 255 / 127);
                        $alphaRaw .= chr($a8);
                        if ($a8 !== 255) {
                            $hasAlpha = true;
                        }
                    }
                }

                imagedestroy($im);


                if ($hasAlpha) {
                    $data = gzcompress($raw);
                    $info = [
                        'w' => $w,
                        'h' => $h,
                        'cs' => $trueColor ? 'DeviceRGB' : 'DeviceGray',
                        'bpc' => $bpc,
                        'f' => 'FlateDecode',
                        'dp' => '/Predictor 15 /Colors ' . $colors . ' /BitsPerComponent ' . $bpc . ' /Columns ' . $w,
                        'data' => $data,
                        'smask' => gzcompress($alphaRaw),
                    ];

                    return $info;
                }



            }
        }

        $f = $this->fileReader->openReadStream($file);

        try {
            $info = $this->parseStream($f, $file);
        } finally {
            fclose($f);
        }

        return $info;
    }

    /**
     * Parse a PNG from a stream resource.
     *
     * @param resource $stream The stream resource to read from
     * @param string $file The file path (for error messages)
     * @return array{w: int, h: int, cs: string, bpc: int, f?: string, data: string, dp?: string, pal?: string, trns?: array<int>, smask?: string}
     */
    public function parseStream($stream, string $file): array
    {
        return $this->parsePngStream($stream, $file);
    }

    public function supports(string $type): bool
    {
        return strtolower($type) === 'png';
    }

    private function parsePngStream($f, string $file): array
    {

        if ($this->readStream($f, 8) !== chr(137) . 'PNG' . chr(13) . chr(10) . chr(26) . chr(10)) {
            throw new FpdfException('Not a PNG file: ' . $file);
        }


        $this->readStream($f, 4);
        $headerType = $this->readStream($f, 4);
        if ($headerType !== 'IHDR') {
            throw new FpdfException('Incorrect PNG file: ' . $file);
        }

        $w = $this->readInt($f);
        $h = $this->readInt($f);
        $bpc = ord($this->readStream($f, 1));
        if ($bpc > 8) {
            throw new FpdfException('16-bit depth not supported: ' . $file);
        }

        $ct = ord($this->readStream($f, 1));
        if ($ct === 0 || $ct === 4) {
            $colspace = 'DeviceGray';
        } elseif ($ct === 2 || $ct === 6) {
            $colspace = 'DeviceRGB';
        } elseif ($ct === 3) {
            $colspace = 'Indexed';
        } else {
            throw new FpdfException('Unknown color type: ' . $file);
        }

        $compressionMethod = ord($this->readStream($f, 1));
        if ($compressionMethod !== 0) {
            throw new FpdfException('Unknown compression method: ' . $file);
        }

        $filterMethod = ord($this->readStream($f, 1));
        if ($filterMethod !== 0) {
            throw new FpdfException('Unknown filter method: ' . $file);
        }

        $interlaceMethod = ord($this->readStream($f, 1));
        if ($interlaceMethod !== 0) {
            throw new FpdfException('Interlacing not supported: ' . $file);
        }

        $this->readStream($f, 4);
        $dp = '/Predictor 15 /Colors ' . ($colspace === 'DeviceRGB' ? 3 : 1) . ' /BitsPerComponent ' . $bpc . ' /Columns ' . $w;


        $pal = '';
        $trns = null;
        $data = '';
        $foundPalette = false;
        do {
            $n = $this->readInt($f);
            $chunkType = $this->readStream($f, 4);
            if ($chunkType === 'PLTE') {
                $pal = $this->readStream($f, $n);
                $foundPalette = true;
                $this->readStream($f, 4);
            } elseif ($chunkType === 'tRNS') {
                $t = $this->readStream($f, $n);
                if ($ct === 0) {
                    $trns = [ord(substr($t, 1, 1))];
                } elseif ($ct === 2) {
                    $trns = [ord(substr($t, 1, 1)), ord(substr($t, 3, 1)), ord(substr($t, 5, 1))];
                } else {
                    $pos = strpos($t, chr(0));
                    if ($pos !== false) {
                        $trns = [$pos];
                    }
                }

                $this->readStream($f, 4);
            } elseif ($chunkType === 'IDAT') {
                $data .= $this->readStream($f, $n);
                $this->readStream($f, 4);
            } elseif ($chunkType === 'IEND') {
                break;
            } else {
                $this->readStream($f, $n + 4);
            }
        } while ($n);

        if ($colspace === 'Indexed' && !$foundPalette) {
            throw new FpdfException('Missing palette in ' . $file);
        }

        $info = [
            'w' => $w,
            'h' => $h,
            'cs' => $colspace,
            'bpc' => $bpc,
            'f' => 'FlateDecode',
            'dp' => $dp,
            'pal' => $pal,
        ];

        if ($trns !== null) {
            $info['trns'] = $trns;
        }

        if ($ct >= 4) {
            if (!function_exists('gzuncompress')) {
                throw new FpdfException('Zlib not available, can\'t handle alpha channel: ' . $file);
            }

            $data = gzuncompress($data);
            $color = '';
            $alpha = '';
            if ($ct === 4) {
                $len = 2 * $w;
                for ($i = 0; $i < $h; $i++) {
                    $pos = (1 + $len) * $i;
                    $color .= $data[$pos];
                    $alpha .= $data[$pos];
                    $line = substr($data, $pos + 1, $len);
                    $color .= preg_replace('/(.)./s', '$1', $line);
                    $alpha .= preg_replace('/.(.)/s', '$1', $line);
                }
            } else {
                $len = 4 * $w;
                for ($i = 0; $i < $h; $i++) {
                    $pos = (1 + $len) * $i;
                    $color .= $data[$pos];
                    $alpha .= $data[$pos];
                    $line = substr($data, $pos + 1, $len);
                    $color .= preg_replace('/(.{3})./s', '$1', $line);
                    $alpha .= preg_replace('/.{3}(.)/s', '$1', $line);
                }
            }

            unset($data);
            $data = gzcompress($color);
            $info['smask'] = gzcompress($alpha);
        }

        $info['data'] = $data;

        return $info;
    }

    private function readStream($f, int $n): string
    {
        $res = '';
        while ($n > 0 && !feof($f)) {
            $s = fread($f, $n);
            if ($s === false) {
                throw new FpdfException('Error while reading stream');
            }

            $n -= strlen($s);
            $res .= $s;
        }

        if ($n > 0) {
            throw new FpdfException('Unexpected end of stream');
        }

        return $res;
    }

    private function readInt($f): int
    {
        $a = unpack('Ni', $this->readStream($f, 4));

        return $a['i'];
    }
}
