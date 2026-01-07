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
namespace PXP\PDF\Fpdf\Features\Metadata;

use function chr;
use function date;
use function function_exists;
use function iconv;
use function ord;
use function strlen;
use function substr;

final class Metadata
{
    private array $data = [];

    public function __construct(string $producer)
    {
        $this->data['Producer'] = $producer;
    }

    public function setTitle(string $title, bool $isUTF8 = false): void
    {
        $this->data['Title'] = $isUTF8 ? $title : $this->encodeUTF8($title);
    }

    public function setAuthor(string $author, bool $isUTF8 = false): void
    {
        $this->data['Author'] = $isUTF8 ? $author : $this->encodeUTF8($author);
    }

    public function setSubject(string $subject, bool $isUTF8 = false): void
    {
        $this->data['Subject'] = $isUTF8 ? $subject : $this->encodeUTF8($subject);
    }

    public function setKeywords(string $keywords, bool $isUTF8 = false): void
    {
        $this->data['Keywords'] = $isUTF8 ? $keywords : $this->encodeUTF8($keywords);
    }

    public function setCreator(string $creator, bool $isUTF8 = false): void
    {
        $this->data['Creator'] = $isUTF8 ? $creator : $this->encodeUTF8($creator);
    }

    public function setCreationDate(int $timestamp): void
    {
        $date                       = date('YmdHisO', $timestamp);
        $this->data['CreationDate'] = 'D:' . substr($date, 0, -2) . "'" . substr($date, -2) . "'";
    }

    public function getAll(): array
    {
        return $this->data;
    }

    private function encodeUTF8(string $s): string
    {
        if (function_exists('iconv')) {
            return iconv('ISO-8859-1', 'UTF-8', $s);
        }

        $res = '';
        $nb  = strlen($s);

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
