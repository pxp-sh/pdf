# CCITTFax Decoder Module

This module provides CCITT Group 3 and Group 4 fax decoding for PDF images, implementing the CCITTFaxDecode filter as specified in the PDF 1.7 specification.

## Structure

- **Decoder/**: Concrete decoder implementations
  - `CCITT3Decoder`: Group 3 1D (MH) encoding
  - `CCITT3MixedDecoder`: Group 3 2D (MR) encoding with 1D/2D alternation
  - `CCITT4Decoder`: Group 4 (MMR) encoding
  - `DecoderFactory`: Factory for creating appropriate decoder based on parameters

- **Interface/**: Contracts
  - `StreamDecoderInterface`: Common interface for all decoders

- **Model/**: Data models and value objects
  - `Params`: CCITT fax parameters (K, columns, rows, etc.)
  - `Mode`: 2D coding modes enumeration
  - `ModeCode`: Mode code representation
  - `HorizontalCode`: Horizontal run-length codes

- **Constants/**: Lookup tables and constants
  - `Codes`: Huffman code tables for run-length encoding
  - `Modes`: 2D mode code tables

- **Util/**: Utility classes
  - `BitBuffer`: Bit-level stream reading
  - `BitmapPacker`: Bitmap data packing/unpacking

## Usage

```php
use PXP\PDF\CCITTFax\Decoder\DecoderFactory;
use PXP\PDF\CCITTFax\Model\Params;

// Create parameters
$params = new Params(
    k: 0, // Group 3 1D
    columns: 1728,
    rows: 0, // Unknown height
    blackIs1: false,
    // ... other params
);

// Create decoder
$decoder = DecoderFactory::createForParams($params, $compressedData);

// Decode to memory
$bitmap = $decoder->decode();

// Or decode to stream for large images
$decoder->decodeToStream($outputStream);
```

## Testing

Unit tests are organized by subfolder:
- `tests/Unit/PDF/CCITTFax/Decoder/`
- `tests/Unit/PDF/CCITTFax/Model/`
- `tests/Unit/PDF/CCITTFax/Util/`

Run tests with:
```bash
composer test:unit
```

## Notes

- Group 4 decoder uses a different constructor (width directly) for compatibility with existing code.
- All decoders implement `StreamDecoderInterface` for consistent API.
- Parameters are encapsulated in `Params` object for type safety.