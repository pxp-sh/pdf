# CCITT Fax Decoder Tests

This directory contains comprehensive tests for the CCITT Fax decoder implementation, including support for streaming to prevent memory overflow.

## Test Structure

### Unit Tests (`tests/Unit/PDF/CCITTFax/`)

1. **CCITT4FaxDecoderTest.php** - Tests for Group 4 (T.6) decoder
   - Legacy `decode()` method tests
   - Streaming `decodeToStream()` method tests
   - Stream input support
   - Color inversion (BlackIs1)
   - Memory efficiency verification
   - Multiple test files with various dimensions

2. **CCITT3FaxDecoderTest.php** - Tests for Group 3 1D (T.4, K=0) decoder
   - Modified Huffman encoding tests
   - EOL marker handling
   - Byte alignment tests
   - Damaged row tolerance
   - Parameter combinations
   - Stream input/output tests

3. **CCITT3MixedDecoderTest.php** - Tests for Group 3 Mixed (T.4, K>0) decoder
   - Mixed 1D/2D encoding tests
   - K parameter validation
   - Tag bit handling
   - Reference line usage
   - Alternating 1D/2D line decoding

4. **BitmapPackerTest.php** - Tests for bitmap packing utility
   - Pixel packing (8 pixels per byte)
   - MSB-first bit ordering
   - Pack/unpack roundtrip tests
   - Streaming output tests (`packLinesToStream`)
   - Single line packing
   - Color inversion
   - Size calculations

5. **CCITTFaxParamsTest.php** - Tests for parameter handling
   - Default values
   - Array deserialization
   - Encoding type detection

### Integration Tests (`tests/Integration/PDF/CCITTFax/`)

**CCITTFaxIntegrationTest.php** - End-to-end tests with real files
- Decodes all `.bin` files in test resources
- Compares streaming vs legacy output
- Tests full streaming workflow (file → decoder → file)
- Memory efficiency comparisons
- Various parameter combinations
- Error handling with invalid data

## Test Files

The tests use real CCITT-encoded fax data from `tests/resources/CCITTFax/testfiles/`:

### Small Test Files (8x8 pixels)
- `b0.bin` to `b9.bin` - Various 8-pixel wide test patterns
- Corresponding `b*_baseline.png` files for visual reference

### Medium Test Files
- `18x18.bin` - 18x18 pixel test image
- `18x18.png` - Visual reference
- `80x80reversed.bin` - 80x80 pixel test with reversed encoding

### Large Test Files (Standard Fax)
- `CCITT.*.bin` - Various standard fax width (1728 pixels) tests
- Different heights and encoding patterns

## Running Tests

### Run All CCITT Tests
```bash
vendor/bin/phpunit tests/Unit/PDF/CCITTFax/
vendor/bin/phpunit tests/Integration/PDF/CCITTFax/
```

### Run Specific Test Class
```bash
vendor/bin/phpunit tests/Unit/PDF/CCITTFax/CCITT4FaxDecoderTest.php
vendor/bin/phpunit tests/Unit/PDF/CCITTFax/BitmapPackerTest.php
```

### Run Specific Test Method
```bash
vendor/bin/phpunit --filter test_decode_group4_streaming_mode tests/Unit/PDF/CCITTFax/CCITT4FaxDecoderTest.php
```

### Run with Coverage
```bash
vendor/bin/phpunit --coverage-html coverage/ tests/Unit/PDF/CCITTFax/
```

## Test Coverage

The test suite covers:

- ✅ **Group 4 (T.6) Decoding** - Pure 2D encoding
- ✅ **Group 3 1D (T.4, K=0)** - Modified Huffman
- ✅ **Group 3 Mixed (T.4, K>0)** - Mixed 1D/2D encoding
- ✅ **Streaming Input** - Read from stream resources
- ✅ **Streaming Output** - Write to stream resources
- ✅ **Memory Efficiency** - Constant memory usage
- ✅ **Legacy Compatibility** - Old API still works
- ✅ **Bitmap Packing** - Pixel array to binary conversion
- ✅ **Color Inversion** - BlackIs1 parameter
- ✅ **Error Handling** - Invalid data and streams
- ✅ **Parameter Handling** - All CCITTFaxParams options
- ✅ **Real File Decoding** - Actual fax-encoded data

## Test Patterns

### Data Providers
Tests use PHPUnit data providers for testing multiple scenarios:

```php
public static function group4TestFilesProvider(): array
{
    return [
        'small 18x18' => ['18x18.bin', 18, 40],
        'small b0' => ['b0.bin', 8, 8],
        // ... more test cases
    ];
}
```

### Memory Testing
Tests verify streaming mode uses constant memory:

```php
$memBefore = memory_get_usage(true);
$decoder->decodeToStream($stream);
$memAfter = memory_get_usage(true);
$memUsed = $memAfter - $memBefore;

$this->assertLessThan(1024 * 1024, $memUsed); // < 1MB
```

### Output Comparison
Tests verify streaming and legacy produce identical output:

```php
$legacyOutput = BitmapPacker::packLines($lines, $width);
$decoder->decodeToStream($stream);
rewind($stream);
$streamOutput = stream_get_contents($stream);

$this->assertEquals($legacyOutput, $streamOutput);
```

## Adding New Tests

### For New Test Files

1. Add `.bin` file to `tests/resources/CCITTFax/testfiles/`
2. Add entry to data provider in appropriate test class
3. Run tests to verify

### For New Features

1. Add unit tests in appropriate `*Test.php` file
2. Add integration test in `CCITTFaxIntegrationTest.php`
3. Update this README if needed

## Known Issues

- Some test files may not be valid for all decoder types (Group 3 vs Group 4)
- Tests gracefully handle format mismatches with try/catch
- Memory tests may show variance on different PHP versions/configurations

## Test Assertions

Common assertions used:

```php
// Basic structure
$this->assertIsArray($lines);
$this->assertGreaterThan(0, count($lines));

// Resource handling
$this->assertIsResource($stream);

// Output verification
$this->assertEquals($expected, $actual);
$this->assertEquals($bytesWritten, strlen($output));

// Memory efficiency
$this->assertLessThan($maxMemory, $actualMemory);

// Error handling
$this->expectException(RuntimeException::class);
$this->expectExceptionMessage('Expected message');
```

## Debugging Failed Tests

### View Test Output
```bash
vendor/bin/phpunit --verbose tests/Unit/PDF/CCITTFax/
```

### Debug Specific Test
```bash
vendor/bin/phpunit --filter test_name --debug
```

### Check Test File
```bash
ls -lh tests/resources/CCITTFax/testfiles/
hexdump -C tests/resources/CCITTFax/testfiles/18x18.bin | head
```

## Performance Benchmarks

Integration tests include performance metrics:
- Decoding speed (lines per second)
- Memory usage (bytes per line)
- Streaming vs legacy comparison

Run with:
```bash
vendor/bin/phpunit --group performance tests/Integration/
```

## Contributing

When adding new tests:
1. Follow existing naming conventions
2. Add docblocks explaining what is tested
3. Use data providers for multiple scenarios
4. Include both positive and negative test cases
5. Test streaming and legacy modes
6. Update this README

## References

- [CCITT T.4 Specification](https://www.itu.int/rec/T-REC-T.4/en)
- [CCITT T.6 Specification](https://www.itu.int/rec/T-REC-T.6/en)
- [PDF 1.7 Specification - CCITTFaxDecode](https://www.adobe.com/content/dam/acom/en/devnet/pdf/pdfs/PDF32000_2008.pdf)
