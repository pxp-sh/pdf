# PDF Text Extraction Examples

This directory contains example scripts demonstrating how to extract text from PDF files using the PXP PDF library.

## Available Examples

### 1. Quick Start (`quick_start_extract.php`)

The simplest example showing basic text extraction:

```bash
php examples/quick_start_extract.php
```

```php
use PXP\PDF\Fpdf\Extractor\Text;

$extractor = new Text();
$text = $extractor->extractFromFile('/path/to/document.pdf');
echo $text;
```

### 2. Complete Examples (`extract_text.php`)

Comprehensive examples covering various scenarios:

```bash
php examples/extract_text.php
```

**Includes:**
- Extracting text from generated PDFs
- Extracting text from PDF files
- Working with multi-page documents
- Processing existing PDF files

### 3. Command-Line Tool (`extract_and_save.php`)

Practical CLI tool for extracting text from PDFs and saving to text files:

```bash
# Extract from PDF and save to TXT file
php examples/extract_and_save.php input.pdf output.txt

# Auto-generate output filename
php examples/extract_and_save.php input.pdf

# Run demo mode
php examples/extract_and_save.php
```

**Features:**
- Command-line interface with argument parsing
- Performance timing and statistics
- Preview of extracted content
- Saves output to text files
- Demo mode for testing

## Usage

### Basic Text Extraction

```php
require 'vendor/autoload.php';

use PXP\PDF\Fpdf\Extractor\Text;

// Create extractor instance
$extractor = new Text();

// Extract from file
$text = $extractor->extractFromFile('document.pdf');
echo $text;
```

### Extract from PDF Content

```php
// If you already have PDF content in memory
$pdfContent = file_get_contents('document.pdf');
$text = $extractor->extractFromString($pdfContent);
echo $text;
```

### Working with Generated PDFs

```php
use PXP\PDF\Fpdf\FPDF;
use PXP\PDF\Fpdf\Extractor\Text;

// Create a PDF
$pdf = new FPDF();
$pdf->addPage();
$pdf->setFont('Arial', '', 12);
$pdf->cell(0, 10, 'Hello World!');

// Get PDF content
$pdfContent = $pdf->output('S', 'doc.pdf');

// Extract text
$extractor = new Text();
$text = $extractor->extractFromString($pdfContent);
echo $text; // Output: Hello World!
```

## Features

- ✅ Extract text from single-page PDFs
- ✅ Extract text from multi-page documents
- ✅ Support for various fonts (Arial, Times, Courier, etc.)
- ✅ Handle different text operators (Tj, TJ, ', ")
- ✅ Process both generated and existing PDF files
- ✅ Memory-efficient streaming for large documents

## Requirements

- PHP 8.1 or higher
- PXP PDF Library
- PDFs with text content (not image-based scans)

## Limitations

- Scanned PDFs (images) require OCR, which is not supported
- Encrypted PDFs may not be readable
- Complex layouts may not preserve exact spacing
- Right-to-left text may need additional processing

## Running the Examples

```bash
# Run the quick start example
php examples/quick_start_extract.php

# Run the comprehensive examples
php examples/extract_text.php
```

## Additional Resources

- [Unit Tests](../tests/Unit/PDF/Fpdf/Extractor/TextTest.php) - See basic functionality tests
- [Feature Tests](../tests/Feature/PDF/TextExtractionTest.php) - See real-world scenario tests
- [Source Code](../src/PDF/Fpdf/Extractor/Text.php) - View the implementation

## Need Help?

Check the test files for more usage examples and edge cases.
