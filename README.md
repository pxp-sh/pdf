# 📄 PXP PDF

[![Packagist Version](https://img.shields.io/packagist/v/pxp/pdf)](https://packagist.org/packages/pxp/pdf)
[![License](https://img.shields.io/github/license/pxp-sh/pdf)](https://github.com/pxp-sh/pdf/blob/main/LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/pxp/pdf)](https://packagist.org/packages/pxp/pdf)
[![GitHub stars](https://img.shields.io/github/stars/pxp-sh/pdf)](https://github.com/pxp-sh/pdf)

A modern PHP library to create, read, extract text from, and manipulate PDF files. 🚀

## ✨ Key Features

- **Create PDFs** using an FPDF-compatible API (`PXP\PDF\Fpdf\FPDF`).
- **Extract text efficiently** from files and buffers (`PXP\PDF\Fpdf\Extractor\Text`).
- **High-level helpers**: `PXP\PDF\PDF` facade for extraction and PDF utilities (split, merge, extract page).
- **Memory-efficient** streaming extraction and page-buffering for large documents.
- **Comprehensive tests**: Unit, Feature, and Integration suites with example scripts.

## 📋 Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Usage](#quick-usage)
- [Examples](#examples)
- [Tests & Quality](#tests--quality)
- [Contributing](#contributing)
- [Acknowledgments](#acknowledgments)
- [License](#license)

---

## 📋 Requirements

- **PHP** ^8.4
- **Composer**

*See `composer.json` for dev dependencies (PHPStan, PHPUnit, PHP CS Fixer, etc.) used in development and CI.*

---

## 📦 Installation

Install via Composer:

```bash
composer require pxp/pdf
```

Include the autoloader in your project:

```php
require 'vendor/autoload.php';
```

---

## 🚀 Quick Usage

### Create a PDF

```php
use PXP\PDF\Fpdf\FPDF;

$pdf = new FPDF();
$pdf->addPage();
$pdf->setFont('Arial', '', 12);
$pdf->cell(0, 10, 'Hello World!');
$pdf->output('F', '/tmp/hello.pdf');
```

### Extract Text (Simple)

```php
use PXP\PDF\PDF;

$text = PDF::extractText('/path/to/document.pdf');
echo $text;
```

### Streaming Extraction

```php
PDF::extractTextStreaming(function(string $text, int $page) {
    echo "Page $page:\n" . $text;
}, '/path/to/document.pdf');
```

### PDF Utilities

Split, extract a page, or merge PDFs using the `PDF` facade:

```php
PDF::splitPdf('big.pdf', '/tmp/pages');
PDF::extractPage('big.pdf', 3, '/tmp/page3.pdf');
PDF::mergePdf(['a.pdf', 'b.pdf'], 'merged.pdf');
```

*Low-level extraction options with `PXP\PDF\Fpdf\Extractor\Text` are demonstrated in the `examples/` directory.*

---

## 📚 Examples

Dive into `examples/` for working scripts:

- `examples/quick_start_extract.php` — Minimal extraction example
- `examples/extract_text.php` — End-to-end examples (create PDF, extract, save per-page files)
- `examples/extract_and_save.php` — CLI tool for extracting and saving text
- `examples/buffer_extraction_easy.php` — Buffer-based extraction and heuristics

**Run an example:**

```bash
php examples/extract_text.php
```

---

## 🧪 Tests & Quality

Run the test suite:

```bash
composer test       # Runs paratest / PHPUnit suites
composer test:unit  # Unit tests only
composer test:feature
composer test:integration
```

Static analysis and coding standards:

```bash
composer phpstan     # Run PHPStan
composer cs:check    # Check code style
composer cs:fix      # Fix code style
```

*When making changes, add tests (unit/feature/integration), run the suites, and keep formatting consistent with the project's style.*

---

## 🤝 Contributing

Contributions are welcome! 🌟

- Fork the repo
- Create a feature branch
- Add tests for new behavior
- Run `composer cs:fix`, `composer phpstan`, and the test suites
- Open a PR describing the change

*See project-specific guidelines in code and tests, and use the project's helper test utilities under `tests/TestCase.php` when writing tests.*

---

## 🙏 Acknowledgments

A special thanks to the following open-source projects that inspired and enabled this library:

- [smalot/pdfparser](https://github.com/smalot/pdfparser) - PDF parsing library
- [FPDF](https://www.fpdf.org/) - Free PDF generation library
- [fpdf2file](https://github.com/jamesj2/fpdf2file) - FPDF extension for file operations
- [CCITTFaxDecode](https://github.com/plaisted/CCITTFaxDecode) - CCITT Fax decoding

---

## �📄 License

This project is licensed under the MIT License — see the `LICENSE` file for details.
