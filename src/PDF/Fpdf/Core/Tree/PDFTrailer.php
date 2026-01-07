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
namespace PXP\PDF\Fpdf\Core\Tree;

use PXP\PDF\Fpdf\Core\Object\Base\PDFArray;
use PXP\PDF\Fpdf\Core\Object\Base\PDFDictionary;
use PXP\PDF\Fpdf\Core\Object\Base\PDFReference;
use PXP\PDF\Fpdf\Core\Object\Base\PDFString;

/**
 * Represents the PDF trailer dictionary.
 */
final class PDFTrailer
{
    private int $size              = 0;
    private ?PDFReference $root    = null;
    private ?PDFReference $info    = null;
    private ?PDFReference $encrypt = null;

    /**
     * @var null|array{0: string, 1: string}
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

    public function setRoot(?PDFReference $pdfReference): void
    {
        $this->root = $pdfReference;
    }

    public function getInfo(): ?PDFReference
    {
        return $this->info;
    }

    public function setInfo(?PDFReference $pdfReference): void
    {
        $this->info = $pdfReference;
    }

    public function getEncrypt(): ?PDFReference
    {
        return $this->encrypt;
    }

    public function setEncrypt(?PDFReference $pdfReference): void
    {
        $this->encrypt = $pdfReference;
    }

    /**
     * @return null|array{0: string, 1: string}
     */
    public function getId(): ?array
    {
        return $this->id;
    }

    /**
     * @param null|array{0: string, 1: string} $id
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
        $pdfDictionary = new PDFDictionary;
        $pdfDictionary->addEntry('/Size', $this->size);

        if ($this->root instanceof PDFReference) {
            $pdfDictionary->addEntry('/Root', $this->root);
        }

        if ($this->info instanceof PDFReference) {
            $pdfDictionary->addEntry('/Info', $this->info);
        }

        if ($this->encrypt instanceof PDFReference) {
            $pdfDictionary->addEntry('/Encrypt', $this->encrypt);
        }

        if ($this->id !== null) {
            $pdfArray = new PDFArray([
                new PDFString($this->id[0], true),
                new PDFString($this->id[1], true),
            ]);
            $pdfDictionary->addEntry('/ID', $pdfArray);
        }

        return $pdfDictionary;
    }

    /**
     * Serialize trailer to PDF format.
     */
    public function serialize(int $xrefOffset): string
    {
        $result = "trailer\n";
        $result .= $this->toDictionary() . "\n";
        $result .= "startxref\n";
        $result .= $xrefOffset . "\n";

        return $result . "%%EOF\n";
    }
}
