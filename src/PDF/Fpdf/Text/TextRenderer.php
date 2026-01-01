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

namespace PXP\PDF\Fpdf\Text;

final class TextRenderer
{
    public function escape(string $s): string
    {
        if (str_contains($s, '(') || str_contains($s, ')') || str_contains($s, '\\') || str_contains($s, "\r")) {
            return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', '\\r'], $s);
        }

        return $s;
    }

    public function getStringWidth(string $s, array $cw, float $fontSize): float
    {
        $w = 0;
        $l = strlen($s);
        for ($i = 0; $i < $l; $i++) {
            $w += $cw[$s[$i]] ?? 0;
        }

        return $w * $fontSize / 1000;
    }

    public function isAscii(string $s): bool
    {
        $nb = strlen($s);
        for ($i = 0; $i < $nb; $i++) {
            if (ord($s[$i]) > 127) {
                return false;
            }
        }

        return true;
    }

    public function utf8ToUtf16(string $s): string
    {
        $res = "\xFE\xFF";
        if (function_exists('iconv')) {
            return $res . iconv('UTF-8', 'UTF-16BE', $s);
        }

        $nb = strlen($s);
        $i = 0;
        while ($i < $nb) {
            $c1 = ord($s[$i++]);
            if ($c1 >= 224) {

                $c2 = ord($s[$i++]);
                $c3 = ord($s[$i++]);
                $res .= chr((($c1 & 0x0F) << 4) + (($c2 & 0x3C) >> 2));
                $res .= chr((($c2 & 0x03) << 6) + ($c3 & 0x3F));
            } elseif ($c1 >= 192) {

                $c2 = ord($s[$i++]);
                $res .= chr(($c1 & 0x1C) >> 2);
                $res .= chr((($c1 & 0x03) << 6) + ($c2 & 0x3F));
            } else {

                $res .= "\0" . chr($c1);
            }
        }

        return $res;
    }

    public function textString(string $s): string
    {
        if (!$this->isAscii($s)) {
            $s = $this->utf8ToUtf16($s);
        }

        return '(' . $this->escape($s) . ')';
    }

    public function httpEncode(string $param, string $value, bool $isUTF8): string
    {
        if ($this->isAscii($value)) {
            return $param . '="' . $value . '"';
        }

        if (!$isUTF8) {
            $value = $this->utf8Encode($value);
        }

        return $param . "*=UTF-8''" . rawurlencode($value);
    }

    public function utf8Encode(string $s): string
    {
        if (function_exists('iconv')) {
            return iconv('ISO-8859-1', 'UTF-8', $s);
        }

        $res = '';
        $nb = strlen($s);
        for ($i = 0; $i < $nb; $i++) {
            $c = $s[$i];
            $v = ord($c);
            if ($v >= 128) {
                $res .= chr(0xC0 | ($v >> 6));
                $res .= chr(0x80 | ($v & 0x3F));
            } else {
                $res .= $c;
            }
        }

        return $res;
    }
}
