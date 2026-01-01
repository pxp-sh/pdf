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

namespace PXP\PDF\Fpdf\Tree;

use PXP\PDF\Fpdf\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Object\Base\PDFReference;

/**
 * Represents the PDF trailer dictionary.
 */
final class PDFTrailer
{
    private int $size = 0;
    private ?PDFReference $root = null;
    private ?PDFReference $info = null;
    private ?PDFReference $encrypt = null;
    /**
     * @var array{0: string, 1: string}|null
     */
    private ?array $id = null;

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): void
    {
        $this->size = $size;
    }

    public function getRoot(): ?PDFReference
    {
        return $this->root;
    }

    public function setRoot(?PDFReference $root): void
    {
        $this->root = $root;
    }

    public function getInfo(): ?PDFReference
    {
        return $this->info;
    }

    public function setInfo(?PDFReference $info): void
    {
        $this->info = $info;
    }

    public function getEncrypt(): ?PDFReference
    {
        return $this->encrypt;
    }

    public function setEncrypt(?PDFReference $encrypt): void
    {
        $this->encrypt = $encrypt;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public function getId(): ?array
    {
        return $this->id;
    }

    /**
     * @param array{0: string, 1: string}|null $id
     */
    public function setId(?array $id): void
    {
        $this->id = $id;
    }

    /**
     * Convert to PDF dictionary format.
     */
    public function toDictionary(): PDFDictionary
    {
        $dict = new PDFDictionary();
        $dict->addEntry('/Size', $this->size);

        if ($this->root !== null) {
            $dict->addEntry('/Root', $this->root);
        }

        if ($this->info !== null) {
            $dict->addEntry('/Info', $this->info);
        }

        if ($this->encrypt !== null) {
            $dict->addEntry('/Encrypt', $this->encrypt);
        }

        if ($this->id !== null) {
            $idArray = new \PXP\PDF\Fpdf\Object\Base\PDFArray([
                new \PXP\PDF\Fpdf\Object\Base\PDFString($this->id[0], true),
                new \PXP\PDF\Fpdf\Object\Base\PDFString($this->id[1], true),
            ]);
            $dict->addEntry('/ID', $idArray);
        }

        return $dict;
    }

    /**
     * Serialize trailer to PDF format.
     */
    public function serialize(int $xrefOffset): string
    {
        $result = "trailer\n";
        $result .= (string) $this->toDictionary() . "\n";
        $result .= "startxref\n";
        $result .= (string) $xrefOffset . "\n";
        $result .= "%%EOF\n";

        return $result;
    }
}
