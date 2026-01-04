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
namespace Test;

use const IMAGETYPE_GIF;
use const IMAGETYPE_JPEG;
use const IMAGETYPE_PNG;
use const PATHINFO_FILENAME;
use const PHP_OS;
use function abs;
use function basename;
use function count;
use function dirname;
use function error_log;
use function escapeshellarg;
use function exec;
use function extension_loaded;
use function file_exists;
use function filesize;
use function getenv;
use function getimagesize;
use function glob;
use function imagecolorat;
use function imagecreatefromgif;
use function imagecreatefromjpeg;
use function imagecreatefrompng;
use function imagedestroy;
use function imagesx;
use function imagesy;
use function implode;
use function is_dir;
use function is_file;
use function is_readable;
use function max;
use function mkdir;
use function pathinfo;
use function preg_match;
use function preg_replace;
use function proc_close;
use function proc_open;
use function realpath;
use function rename;
use function sprintf;
use function str_contains;
use function stream_get_contents;
use function substr;
use function trim;
use function uniqid;
use function unlink;
use Composer\Autoload\ClassLoader;
use GdImage;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\MemoryPeakUsageProcessor;
use Monolog\Processor\MemoryUsageProcessor;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use PXP\PDF\Fpdf\Enum\PageOrientation;
use PXP\PDF\Fpdf\Enum\Unit;
use PXP\PDF\Fpdf\FPDF;
use PXP\PDF\Fpdf\IO\FileIO;
use PXP\PDF\Fpdf\ValueObject\PageSize;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;

class TestCase extends \PHPUnit\Framework\TestCase
{
    private static ?LoggerInterface $logger                   = null;
    private static ?CacheItemPoolInterface $cache             = null;
    private static ?EventDispatcherInterface $eventDispatcher = null;

    /**
     * Get the shared logger instance for tests.
     */
    public static function getLogger(): LoggerInterface
    {
        if (self::$logger === null) {
            $logger  = new Logger('test');
            $handler = new StreamHandler('php://stdout', Level::Debug);
            $logger->pushHandler($handler);
            $logger->pushProcessor(new MemoryUsageProcessor);
            $logger->pushProcessor(new MemoryPeakUsageProcessor);
            self::$logger = $logger;
        }

        return self::$logger;
    }

    /**
     * Get the shared cache instance for tests.
     * Note: Cache is cleared before each test run to ensure consistent results.
     */
    public static function getCache(): CacheItemPoolInterface
    {
        if (self::$cache === null) {
            $cacheDir = self::getRootDir() . '/cache';

            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0o777, true);
            }
            self::$cache = new FilesystemAdapter('', 0, $cacheDir);
            // Clear cache to ensure consistent test results across runs
            self::$cache->clear();
        }

        return self::$cache;
    }

    /**
     * Get the shared event dispatcher instance for tests.
     */
    public static function getEventDispatcher(): EventDispatcherInterface
    {
        if (self::$eventDispatcher === null) {
            self::$eventDispatcher = new EventDispatcher;
        }

        return self::$eventDispatcher;
    }

    /**
     * Create an FPDF instance with test PSR implementations.
     */
    public static function createFPDF(
        PageOrientation|string $orientation = 'P',
        string|Unit $unit = 'mm',
        array|PageSize|string $size = 'A4',
    ): FPDF {
        return new FPDF(
            $orientation,
            $unit,
            $size,
            null,
            self::getLogger(),
            self::getCache(),
            self::getEventDispatcher(),
        );
    }

    /**
     * Create a FileIO instance with test logger.
     */
    public static function createFileIO(): FileIO
    {
        return new FileIO(self::getLogger());
    }

    public static function getRootDir(): string
    {
        $classLoader = new ReflectionClass(
            ClassLoader::class,
        );
        $fileName = $classLoader->getFileName();
        $dirname  = dirname($fileName, 3) . '/var/tmp';

        if (!is_dir($dirname)) {
            mkdir($dirname, 0o777, true);
        }

        return $dirname;
    }

    public static function unlink(string $filepath): void
    {
        if (getenv('PERSISTENT_PDF_TEST_FILES') === '1') {
            return;
        }

        if (is_file($filepath) && file_exists($filepath)) {
            @unlink($filepath);
        }
    }

    /**
     * Convert PDF page to image using external CLI tool.
     *
     * @param string      $pdfPath    Path to PDF file
     * @param int         $pageNumber Page number (1-based)
     * @param null|string $outputPath Optional output path for image (default: auto-generated)
     *
     * @throws RuntimeException If no suitable tool is available or conversion fails
     *
     * @return string Path to generated image file
     */
    public static function pdfToImage(string $pdfPath, int $pageNumber = 1, ?string $outputPath = null): string
    {
        // Check file exists first, then normalize path
        if (!file_exists($pdfPath)) {
            throw new RuntimeException('PDF file not found: ' . $pdfPath);
        }

        // Normalize path (realpath returns false if file doesn't exist, but we already checked)
        $normalizedPath = realpath($pdfPath);
        $pdfPath        = $normalizedPath !== false ? $normalizedPath : $pdfPath;

        if ($outputPath === null) {
            $outputPath = self::getRootDir() . '/pdf_image_' . uniqid() . '_page' . $pageNumber . '.png';
        }

        // Ensure output directory exists
        $outputDir = dirname($outputPath);

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0o777, true);
        }

        // Try mutool first (MuPDF) - best for Core fonts, doesn't require system fonts
        if (self::commandExists('mutool')) {
            return self::pdfToImageWithMutool($pdfPath, $pageNumber, $outputPath);
        }

        // Try pdftoppm (poppler-utils)
        if (self::commandExists('pdftoppm')) {
            return self::pdfToImageWithPdftoppm($pdfPath, $pageNumber, $outputPath);
        }

        // Try ImageMagick convert
        if (self::commandExists('convert')) {
            return self::pdfToImageWithImageMagick($pdfPath, $pageNumber, $outputPath);
        }

        // Try Ghostscript
        if (self::commandExists('gs')) {
            return self::pdfToImageWithGhostscript($pdfPath, $pageNumber, $outputPath);
        }

        $error = 'No PDF to image conversion tool found. Please install one of: mutool (MuPDF), ' .
            'pdftoppm (poppler-utils), ImageMagick (convert), or Ghostscript (gs)';

        throw new RuntimeException($error);
    }

    /**
     * Calculate image similarity using perceptual hash or pixel comparison.
     *
     * @param string $imagePath1 Path to first image
     * @param string $imagePath2 Path to second image
     *
     * @throws RuntimeException If images cannot be compared
     *
     * @return float Similarity score between 0.0 (completely different) and 1.0 (identical)
     */
    public static function compareImages(string $imagePath1, string $imagePath2): float
    {
        if (!file_exists($imagePath1)) {
            throw new RuntimeException('First image file not found: ' . $imagePath1);
        }

        if (!file_exists($imagePath2)) {
            throw new RuntimeException('Second image file not found: ' . $imagePath2);
        }

        // Try using ImageMagick compare if available
        if (self::commandExists('compare')) {
            try {
                return self::compareImagesWithImageMagick($imagePath1, $imagePath2);
            } catch (RuntimeException $e) {
                // If ImageMagick fails (e.g., no PNG support), fall back to GD
                if (
                    str_contains($e->getMessage(), 'no decode delegate') ||
                    str_contains($e->getMessage(), 'ImageMagick compare failed')
                ) {
                    return self::compareImagesWithGD($imagePath1, $imagePath2);
                }

                // Re-throw other exceptions
                throw $e;
            }
        }

        // Fallback to PHP GD-based comparison
        return self::compareImagesWithGD($imagePath1, $imagePath2);
    }

    /**
     * Assert that two PDFs have visually similar pages.
     *
     * @param string $originalPdf   Path to original PDF
     * @param string $splitPdf      Path to split/extracted PDF
     * @param int    $pageNumber    Page number to compare (1-based)
     * @param float  $minSimilarity Minimum similarity threshold (0.0 to 1.0, default: 0.95)
     * @param string $message       Optional assertion message
     */
    public function assertPdfPagesSimilar(
        string $originalPdf,
        string $splitPdf,
        int $pageNumber,
        float $minSimilarity = 0.95,
        string $message = ''
    ): void {
        try {
            // Resolve paths and verify files exist
            $originalPdf = realpath($originalPdf) ?: $originalPdf;
            $splitPdf    = realpath($splitPdf) ?: $splitPdf;

            if (!file_exists($originalPdf)) {
                throw new RuntimeException('Original PDF file not found: ' . $originalPdf);
            }

            if (!file_exists($splitPdf)) {
                throw new RuntimeException('Split PDF file not found: ' . $splitPdf);
            }

            // Check if PDFs have text content (to detect font rendering issues)
            $originalHasText = self::pdfHasTextContent($originalPdf, $pageNumber);
            $splitHasText    = self::pdfHasTextContent($splitPdf, 1);

            $originalImage = self::pdfToImage($originalPdf, $pageNumber);
            $splitImage    = self::pdfToImage($splitPdf, 1); // Split PDFs are single-page, always page 1

            // Check if images are blank when they shouldn't be
            $originalIsBlank = self::isImageMostlyWhite($originalImage);
            $splitIsBlank    = self::isImageMostlyWhite($splitImage);

            // If PDF has text but image is blank, this indicates a rendering problem
            // Instead of failing immediately, we'll still do the comparison but note the issue
            $renderingIssue = false;
            $issueMessage   = '';

            if ($originalHasText && $originalIsBlank) {
                $renderingIssue = true;
                $originalText   = self::extractPdfText($originalPdf, $pageNumber);
                $issueMessage .= 'Original PDF has text ("' . substr($originalText, 0, 50) . '") but image is blank (font rendering issue). ';
            }

            if ($splitHasText && $splitIsBlank) {
                $renderingIssue = true;
                $splitText      = self::extractPdfText($splitPdf, 1);
                $issueMessage .= 'Split PDF has text ("' . substr($splitText, 0, 50) . '") but image is blank. ';
            }

            // If both PDFs have text but both images are blank, use text comparison as fallback
            // This handles cases where the PDF-to-image tool can't render custom fonts
            if ($originalHasText && $splitHasText && $originalIsBlank && $splitIsBlank) {
                // Use text extraction for comparison instead of image comparison
                $originalText = self::extractPdfText($originalPdf, $pageNumber);
                $splitText    = self::extractPdfText($splitPdf, 1);

                // Clean up temporary images
                if (!getenv('PDF_TEST_DEBUG')) {
                    self::unlink($originalImage);
                    self::unlink($splitImage);
                }

                // Compare text content
                $textMatches = trim($originalText) === trim($splitText);

                $msg = $message ?: sprintf(
                    'PDF pages text content does not match | Original: %s | Split: %s',
                    basename($originalPdf),
                    basename($splitPdf),
                );

                $msg .= ' | NOTE: ' . $issueMessage .
                    'Using text extraction comparison due to font rendering limitations in image conversion.';

                $this->assertTrue(
                    $textMatches,
                    $msg . ' | Original text: "' . substr($originalText, 0, 100) . '" | Split text: "' . substr($splitText, 0, 100) . '"',
                );

                return; // Skip image comparison
            }

            // If both are blank due to rendering issues, the comparison will still work
            // but we'll get low similarity, which is correct behavior

            $similarity = self::compareImages($originalImage, $splitImage);

            // Debug: Log similarity and image paths if similarity is suspiciously high for different content
            if (getenv('PDF_TEST_DEBUG')) {
                error_log(sprintf(
                    'PDF Comparison Debug: similarity=%.4f, original=%s, split=%s, originalImage=%s, splitImage=%s',
                    $similarity,
                    $originalPdf,
                    $splitPdf,
                    $originalImage,
                    $splitImage,
                ));
            }

            // Clean up temporary images (unless debugging)
            if (!getenv('PDF_TEST_DEBUG')) {
                self::unlink($originalImage);
                self::unlink($splitImage);
            }

            $msg = $message ?: sprintf(
                'PDF pages are not similar enough. Similarity: %.2f%%, Required: %.2f%% | Original: %s | Split: %s',
                $similarity * 100,
                $minSimilarity * 100,
                basename($originalPdf),
                basename($splitPdf),
            );

            // Add rendering issue information to the message if applicable
            if ($renderingIssue) {
                $msg .= ' | NOTE: ' . $issueMessage .
                    'This may be due to font rendering limitations. ' .
                    'If both PDFs have text but images are blank, similarity will be low.';
            }

            $this->assertGreaterThanOrEqual(
                $minSimilarity,
                $similarity,
                $msg,
            );
        } catch (RuntimeException $e) {
            // Only skip if it's a "tools not available" error, otherwise let the test fail
            if (str_contains($e->getMessage(), 'No PDF to image conversion tool found')) {
                $this->markTestSkipped('PDF to image conversion not available: ' . $e->getMessage());
            } else {
                // Re-throw other exceptions so the test fails with a clear error
                throw $e;
            }
        }
    }

    /**
     * Assert that a PDF page matches expected visual content.
     *
     * @param string $pdfPath           Path to PDF file
     * @param int    $pageNumber        Page number to check (1-based)
     * @param string $expectedImagePath Path to expected image file
     * @param float  $minSimilarity     Minimum similarity threshold (0.0 to 1.0, default: 0.95)
     * @param string $message           Optional assertion message
     */
    public function assertPdfPageMatchesImage(
        string $pdfPath,
        int $pageNumber,
        string $expectedImagePath,
        float $minSimilarity = 0.95,
        string $message = ''
    ): void {
        try {
            $pdfImage = self::pdfToImage($pdfPath, $pageNumber);

            $similarity = self::compareImages($expectedImagePath, $pdfImage);

            // Clean up temporary image
            self::unlink($pdfImage);

            $msg = $message ?: sprintf(
                'PDF page does not match expected image. Similarity: %.2f%%, Required: %.2f%%',
                $similarity * 100,
                $minSimilarity * 100,
            );

            $this->assertGreaterThanOrEqual(
                $minSimilarity,
                $similarity,
                $msg,
            );
        } catch (RuntimeException $e) {
            // Only skip if it's a "tools not available" error, otherwise let the test fail
            if (str_contains($e->getMessage(), 'No PDF to image conversion tool found')) {
                $this->markTestSkipped('PDF to image conversion not available: ' . $e->getMessage());
            } else {
                // Re-throw other exceptions so the test fails with a clear error
                throw $e;
            }
        }
    }

    /**
     * Assert that all pages in a split PDF match the original PDF pages.
     *
     * @param string        $originalPdf   Path to original multi-page PDF
     * @param array<string> $splitPdfs     Array of paths to split single-page PDFs
     * @param float         $minSimilarity Minimum similarity threshold (0.0 to 1.0, default: 0.95)
     * @param string        $message       Optional assertion message
     */
    public function assertSplitPdfPagesMatchOriginal(
        string $originalPdf,
        array $splitPdfs,
        float $minSimilarity = 0.95,
        string $message = ''
    ): void {
        try {
            // Resolve paths and verify files exist
            $originalPdf = realpath($originalPdf) ?: $originalPdf;

            if (!file_exists($originalPdf)) {
                throw new RuntimeException('Original PDF file not found: ' . $originalPdf);
            }

            // Resolve all split PDF paths
            $resolvedSplitPdfs = [];

            foreach ($splitPdfs as $splitPdf) {
                $resolved = realpath($splitPdf) ?: $splitPdf;

                if (!file_exists($resolved)) {
                    throw new RuntimeException('Split PDF file not found: ' . $splitPdf);
                }
                $resolvedSplitPdfs[] = $resolved;
            }

            $originalImages = [];
            $splitImages    = [];

            // Convert all original pages to images
            for ($i = 0; $i < count($resolvedSplitPdfs); $i++) {
                $originalImages[] = self::pdfToImage($originalPdf, $i + 1);
            }

            // Convert all split PDFs to images
            foreach ($resolvedSplitPdfs as $splitPdf) {
                $splitImages[] = self::pdfToImage($splitPdf, 1);
            }

            // Compare each pair
            for ($i = 0; $i < count($resolvedSplitPdfs); $i++) {
                $originalPageNum = $i + 1;
                $originalHasText = self::pdfHasTextContent($originalPdf, $originalPageNum);
                $splitHasText    = self::pdfHasTextContent($resolvedSplitPdfs[$i], 1);
                $originalIsBlank = self::isImageMostlyWhite($originalImages[$i]);
                $splitIsBlank    = self::isImageMostlyWhite($splitImages[$i]);

                // If both PDFs have text but both images are blank, use text comparison
                if ($originalHasText && $splitHasText && $originalIsBlank && $splitIsBlank) {
                    $originalText = self::extractPdfText($originalPdf, $originalPageNum);
                    $splitText    = self::extractPdfText($resolvedSplitPdfs[$i], 1);

                    // Clean up
                    self::unlink($originalImages[$i]);
                    self::unlink($splitImages[$i]);

                    $textMatches = trim($originalText) === trim($splitText);

                    $msg = $message ?: sprintf(
                        'Split PDF page %d text content does not match original page %d',
                        $i + 1,
                        $i + 1,
                    );

                    $msg .= ' | NOTE: Using text extraction comparison due to font rendering limitations.';

                    $this->assertTrue(
                        $textMatches,
                        $msg . ' | Original text: "' . substr($originalText, 0, 100) . '" | Split text: "' . substr($splitText, 0, 100) . '"',
                    );

                    continue; // Skip image comparison for this page
                }

                $similarity = self::compareImages($originalImages[$i], $splitImages[$i]);

                // Clean up
                self::unlink($originalImages[$i]);
                self::unlink($splitImages[$i]);

                $msg = $message ?: sprintf(
                    'Split PDF page %d does not match original page %d. Similarity: %.2f%%, Required: %.2f%%',
                    $i + 1,
                    $i + 1,
                    $similarity * 100,
                    $minSimilarity * 100,
                );

                $this->assertGreaterThanOrEqual(
                    $minSimilarity,
                    $similarity,
                    $msg,
                );
            }
        } catch (RuntimeException $e) {
            // Only skip if it's a "tools not available" error, otherwise let the test fail
            if (str_contains($e->getMessage(), 'No PDF to image conversion tool found')) {
                $this->markTestSkipped('PDF to image conversion not available: ' . $e->getMessage());
            } else {
                // Re-throw other exceptions so the test fails with a clear error
                throw $e;
            }
        }
    }

    /**
     * Get the number of pages in a PDF file.
     *
     * @param string $pdfPath Path to PDF file
     *
     * @return int Number of pages (1 if unable to determine, as safe default)
     */
    protected static function getPdfPageCount(string $pdfPath): int
    {
        // Try using pdfinfo if available (poppler-utils)
        if (self::commandExists('pdfinfo')) {
            $command = sprintf('pdfinfo %s 2>&1', escapeshellarg($pdfPath));
            exec($command, $output, $returnCode);

            if ($returnCode === 0) {
                $outputStr = implode("\n", $output);

                // Look for "Pages: N" in the output
                if (preg_match('/Pages:\s*(\d+)/i', $outputStr, $matches)) {
                    return (int) $matches[1];
                }
            }
        }

        // Fallback: try pdftoppm with a high page number to get error message with page count
        // pdftoppm will fail with "Wrong page range" if page number is too high
        // We can parse the error to get the actual page count, or try incremental approach
        if (self::commandExists('pdftoppm')) {
            // Try to extract a very high page number - pdftoppm will tell us the max
            $testCommand = sprintf('pdftoppm -png -f 9999 -l 9999 %s /tmp/pdf_pagecount_test 2>&1', escapeshellarg($pdfPath));
            exec($testCommand, $testOutput, $testReturnCode);

            if ($testReturnCode !== 0) {
                $errorMsg = implode("\n", $testOutput);

                // Look for "last page (N)" in error message
                if (preg_match('/last page \((\d+)\)/i', $errorMsg, $matches)) {
                    return (int) $matches[1];
                }

                // Look for "can not be after the last page \((\d+)\)"
                if (preg_match('/last page \((\d+)\)/i', $errorMsg, $matches)) {
                    return (int) $matches[1];
                }
            }
        }

        // If we can't determine, return 1 as safe default
        // The calling code will handle single-page PDFs correctly
        return 1;
    }

    /**
     * Check if an image is mostly white (blank page detection).
     *
     * @param string $imagePath Path to image file
     *
     * @return bool True if image is >99% white
     */
    protected static function isImageMostlyWhite(string $imagePath): bool
    {
        if (!extension_loaded('gd')) {
            return false;
        }

        $img = self::loadImage($imagePath);

        if ($img === null) {
            return false;
        }

        $width          = imagesx($img);
        $height         = imagesy($img);
        $totalPixels    = $width * $height;
        $nonWhitePixels = 0;

        // Sample pixels (check every 10th pixel for performance)
        for ($x = 0; $x < $width; $x += 10) {
            for ($y = 0; $y < $height; $y += 10) {
                $rgb = imagecolorat($img, $x, $y);
                $r   = ($rgb >> 16) & 0xFF;
                $g   = ($rgb >> 8) & 0xFF;
                $b   = $rgb & 0xFF;

                if (!($r >= 250 && $g >= 250 && $b >= 250)) {
                    $nonWhitePixels++;
                }
            }
        }

        imagedestroy($img);

        $sampledPixels = (int) (($width / 10) * ($height / 10));
        $whiteRatio    = 1.0 - ($nonWhitePixels / max(1, $sampledPixels));

        return $whiteRatio > 0.99; // >99% white is considered blank
    }

    /**
     * Convert PDF to image using MuPDF's mutool.
     * MuPDF has built-in Core font support and doesn't require system fonts.
     */
    private static function pdfToImageWithMutool(string $pdfPath, int $pageNumber, string $outputPath): string
    {
        // Verify PDF file exists and is readable
        if (!file_exists($pdfPath) || !is_readable($pdfPath)) {
            throw new RuntimeException('PDF file does not exist or is not readable: ' . $pdfPath);
        }

        // Get the actual page count of the PDF
        $actualPageCount = self::getPdfPageCount($pdfPath);

        // Adjust page number if it exceeds the PDF's page count
        // For single-page PDFs (like split pages), always use page 1
        // For multi-page PDFs, use the last page if requested page is out of range
        if ($actualPageCount === 1) {
            $actualPageNumber = 1;
        } elseif ($pageNumber > $actualPageCount) {
            $actualPageNumber = $actualPageCount;
        } else {
            $actualPageNumber = $pageNumber;
        }

        // Ensure output directory exists
        $outputDir = dirname($outputPath);

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0o777, true);
        }

        // mutool draw: mutool draw -o <output> -F <format> -r <dpi> <pdf> <page-range>
        // -F png: explicitly specify PNG output format
        // -r 200: resolution in DPI
        // -o: output file
        // Page numbers are 1-based
        $command = sprintf(
            'mutool draw -o %s -F png -r 200 %s %d 2>&1',
            escapeshellarg($outputPath),
            escapeshellarg($pdfPath),
            $actualPageNumber,
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $errorMsg = implode("\n", $output);

            throw new RuntimeException(
                'mutool failed (exit code ' . $returnCode . '): ' . $errorMsg .
                ' | PDF: ' . $pdfPath . ' | Command: ' . $command,
            );
        }

        if (!file_exists($outputPath)) {
            throw new RuntimeException('mutool did not generate expected image file: ' . $outputPath);
        }

        return $outputPath;
    }

    /**
     * Convert PDF to image using pdftoppm.
     */
    private static function pdfToImageWithPdftoppm(string $pdfPath, int $pageNumber, string $outputPath): string
    {
        // Verify PDF file exists and is readable
        if (!file_exists($pdfPath) || !is_readable($pdfPath)) {
            throw new RuntimeException('PDF file does not exist or is not readable: ' . $pdfPath);
        }

        // Get the actual page count of the PDF
        $actualPageCount = self::getPdfPageCount($pdfPath);

        // Adjust page number if it exceeds the PDF's page count
        // For single-page PDFs (like split pages), always use page 1
        // For multi-page PDFs, use the last page if requested page is out of range
        if ($actualPageCount === 1) {
            $actualPageNumber = 1;
        } elseif ($pageNumber > $actualPageCount) {
            $actualPageNumber = $actualPageCount;
        } else {
            $actualPageNumber = $pageNumber;
        }

        $baseName = pathinfo($outputPath, PATHINFO_FILENAME);
        $dir      = dirname($outputPath);

        // Ensure output directory exists
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        $outputPrefix = $dir . '/' . $baseName;

        // pdftoppm command: pdftoppm -png -f <first> -l <last> -r <dpi> <pdf> <output-prefix>
        // Use higher DPI (200) for better quality and font rendering
        // -freetype yes: Enable FreeType font rendering
        // Note: Core fonts like Helvetica may show "Couldn't find a font" warnings on stderr,
        // but these are non-fatal - pdftoppm will still render (may use substitute fonts)
        // We suppress stderr warnings but check return code for actual errors
        $command = sprintf(
            'pdftoppm -png -f %d -l %d -r 200 -freetype yes %s %s 2>/dev/null',
            $actualPageNumber,
            $actualPageNumber,
            escapeshellarg($pdfPath),
            escapeshellarg($outputPrefix),
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $errorMsg = implode("\n", $output);

            throw new RuntimeException(
                'pdftoppm failed (exit code ' . $returnCode . '): ' . $errorMsg .
                ' | PDF: ' . $pdfPath . ' | Command: ' . $command,
            );
        }

        // pdftoppm generates files with zero-padded suffixes: -0001.png, -0002.png, etc.
        // Try both formats: -1.png and -0001.png
        // Use actualPageNumber since that's what pdftoppm used to generate the file
        $generatedFile       = $outputPrefix . '-' . $actualPageNumber . '.png';
        $generatedFilePadded = $outputPrefix . '-' . sprintf('%04d', $actualPageNumber) . '.png';

        // Check for zero-padded format first (most common)
        if (file_exists($generatedFilePadded)) {
            // Rename to desired output path
            if ($generatedFilePadded !== $outputPath) {
                if (!rename($generatedFilePadded, $outputPath)) {
                    throw new RuntimeException('Failed to rename generated image file from ' . $generatedFilePadded . ' to ' . $outputPath);
                }
            }

            return $outputPath;
        }

        // Check for non-padded format
        if (file_exists($generatedFile)) {
            // Rename to desired output path
            if ($generatedFile !== $outputPath) {
                if (!rename($generatedFile, $outputPath)) {
                    throw new RuntimeException('Failed to rename generated image file from ' . $generatedFile . ' to ' . $outputPath);
                }
            }

            return $outputPath;
        }

        // List files in directory for debugging
        $dirFiles = glob($dir . '/' . $baseName . '-*.png');

        throw new RuntimeException(
            'pdftoppm did not generate expected image file. Tried: ' . $generatedFile . ' and ' . $generatedFilePadded .
            ' (found files: ' . implode(', ', $dirFiles ?: []) . ')',
        );
    }

    /**
     * Convert PDF to image using ImageMagick convert.
     */
    private static function pdfToImageWithImageMagick(string $pdfPath, int $pageNumber, string $outputPath): string
    {
        // Get the actual page count of the PDF
        $actualPageCount = self::getPdfPageCount($pdfPath);

        // Adjust page number if it exceeds the PDF's page count
        // For single-page PDFs (like split pages), always use page 1
        // For multi-page PDFs, use the last page if requested page is out of range
        if ($actualPageCount === 1) {
            $actualPageNumber = 1;
        } elseif ($pageNumber > $actualPageCount) {
            $actualPageNumber = $actualPageCount;
        } else {
            $actualPageNumber = $pageNumber;
        }

        // escapeshellarg already adds quotes, so don't add extra quotes
        $command = sprintf(
            'convert -density 150 %s[%d] %s 2>&1',
            escapeshellarg($pdfPath),
            $actualPageNumber - 1, // ImageMagick uses 0-based indexing
            escapeshellarg($outputPath),
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new RuntimeException('ImageMagick convert failed: ' . implode("\n", $output));
        }

        if (!file_exists($outputPath)) {
            throw new RuntimeException('ImageMagick did not generate expected image file');
        }

        return $outputPath;
    }

    /**
     * Convert PDF to image using Ghostscript.
     */
    private static function pdfToImageWithGhostscript(string $pdfPath, int $pageNumber, string $outputPath): string
    {
        // Get the actual page count of the PDF
        $actualPageCount = self::getPdfPageCount($pdfPath);

        // Adjust page number if it exceeds the PDF's page count
        // For single-page PDFs (like split pages), always use page 1
        // For multi-page PDFs, use the last page if requested page is out of range
        if ($actualPageCount === 1) {
            $actualPageNumber = 1;
        } elseif ($pageNumber > $actualPageCount) {
            $actualPageNumber = $actualPageCount;
        } else {
            $actualPageNumber = $pageNumber;
        }

        // escapeshellarg already adds quotes, so don't add extra quotes
        $command = sprintf(
            'gs -dNOPAUSE -dBATCH -sDEVICE=png16m -dFirstPage=%d -dLastPage=%d -r150 -sOutputFile=%s %s 2>&1',
            $actualPageNumber,
            $actualPageNumber,
            escapeshellarg($outputPath),
            escapeshellarg($pdfPath),
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new RuntimeException('Ghostscript failed: ' . implode("\n", $output));
        }

        if (!file_exists($outputPath)) {
            throw new RuntimeException('Ghostscript did not generate expected image file');
        }

        return $outputPath;
    }

    /**
     * Check if a command exists in PATH.
     */
    private static function commandExists(string $command): bool
    {
        $whereIsCommand = (PHP_OS === 'WINNT') ? 'where' : 'which';

        $process = proc_open(
            "{$whereIsCommand} {$command}",
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if ($process === false) {
            return false;
        }

        $stdout     = stream_get_contents($pipes[1]);
        $returnCode = proc_close($process);

        return $returnCode === 0 && !empty(trim($stdout));
    }

    /**
     * Compare images using ImageMagick compare command.
     */
    private static function compareImagesWithImageMagick(string $imagePath1, string $imagePath2): float
    {
        // Verify both images exist and are readable
        if (!file_exists($imagePath1) || !is_readable($imagePath1)) {
            throw new RuntimeException('First image file not found or not readable: ' . $imagePath1);
        }

        if (!file_exists($imagePath2) || !is_readable($imagePath2)) {
            throw new RuntimeException('Second image file not found or not readable: ' . $imagePath2);
        }

        // Verify images are valid (have content)
        if (filesize($imagePath1) === 0) {
            throw new RuntimeException('First image file is empty: ' . $imagePath1);
        }

        if (filesize($imagePath2) === 0) {
            throw new RuntimeException('Second image file is empty: ' . $imagePath2);
        }

        // Quick check: if both images appear to be blank (using GD), return low similarity
        // This helps catch cases where PDFs render as blank pages
        if (extension_loaded('gd')) {
            $isBlank1 = self::isImageMostlyWhite($imagePath1);
            $isBlank2 = self::isImageMostlyWhite($imagePath2);

            // If both are blank, this is suspicious - return low similarity
            if ($isBlank1 && $isBlank2) {
                return 0.1; // Very low similarity to fail the test
            }

            // If one is blank and the other isn't, similarity should be 0
            if ($isBlank1 !== $isBlank2) {
                return 0.0;
            }
        }

        $tempDiff = self::getRootDir() . '/img_diff_' . uniqid() . '.png';
        // escapeshellarg already adds quotes, so don't add extra quotes
        $command = sprintf(
            'compare -metric RMSE %s %s %s 2>&1',
            escapeshellarg($imagePath1),
            escapeshellarg($imagePath2),
            escapeshellarg($tempDiff),
        );

        exec($command, $output, $returnCode);
        $outputStr = implode("\n", $output);

        // Clean up temp file
        if (file_exists($tempDiff)) {
            self::unlink($tempDiff);
        }

        // Parse RMSE output: "1234.56 (0.1234)" format
        if (preg_match('/\(([\d.]+)\)/', $outputStr, $matches)) {
            $rmse = (float) $matches[1];

            // Convert RMSE (0-1) to similarity (1-0)
            return max(0.0, 1.0 - $rmse);
        }

        // If images are identical, compare returns 0
        if ($returnCode === 0 && str_contains($outputStr, '0 (0)')) {
            return 1.0;
        }

        // Check for specific error messages that indicate ImageMagick can't handle the format
        if (
            str_contains($outputStr, 'no decode delegate') ||
            str_contains($outputStr, 'no encode delegate') ||
            str_contains($outputStr, 'unable to open image')
        ) {
            // ImageMagick doesn't support this format or can't read the images, throw to trigger GD fallback
            throw new RuntimeException('ImageMagick cannot process these images: ' . $outputStr);
        }

        // If compare succeeded but we can't parse the output, fall back to GD for more accurate comparison
        // This handles cases where ImageMagick output format is unexpected or images are both blank
        if ($returnCode === 0) {
            return self::compareImagesWithGD($imagePath1, $imagePath2);
        }

        throw new RuntimeException('ImageMagick compare failed: ' . $outputStr);
    }

    /**
     * Compare images using PHP GD library.
     */
    private static function compareImagesWithGD(string $imagePath1, string $imagePath2): float
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('GD extension is required for image comparison');
        }

        $img1 = self::loadImage($imagePath1);
        $img2 = self::loadImage($imagePath2);

        if ($img1 === null || $img2 === null) {
            throw new RuntimeException('Failed to load images for comparison');
        }

        $width1  = imagesx($img1);
        $height1 = imagesy($img1);
        $width2  = imagesx($img2);
        $height2 = imagesy($img2);

        // If dimensions differ, similarity is 0
        if ($width1 !== $width2 || $height1 !== $height2) {
            imagedestroy($img1);
            imagedestroy($img2);

            return 0.0;
        }

        // Compare pixels
        $totalPixels     = $width1 * $height1;
        $matchingPixels  = 0;
        $nonWhitePixels1 = 0;
        $nonWhitePixels2 = 0;

        for ($x = 0; $x < $width1; $x++) {
            for ($y = 0; $y < $height1; $y++) {
                $rgb1 = imagecolorat($img1, $x, $y);
                $rgb2 = imagecolorat($img2, $x, $y);

                // Allow small differences (tolerance for compression artifacts)
                $r1 = ($rgb1 >> 16) & 0xFF;
                $g1 = ($rgb1 >> 8) & 0xFF;
                $b1 = $rgb1 & 0xFF;
                $r2 = ($rgb2 >> 16) & 0xFF;
                $g2 = ($rgb2 >> 8) & 0xFF;
                $b2 = $rgb2 & 0xFF;

                // Count non-white pixels (to detect blank pages)
                if (!($r1 >= 250 && $g1 >= 250 && $b1 >= 250)) {
                    $nonWhitePixels1++;
                }

                if (!($r2 >= 250 && $g2 >= 250 && $b2 >= 250)) {
                    $nonWhitePixels2++;
                }

                $diff = abs($r1 - $r2) + abs($g1 - $g2) + abs($b1 - $b2);

                if ($diff <= 3) { // Allow 1 pixel difference per channel
                    $matchingPixels++;
                }
            }
        }

        imagedestroy($img1);
        imagedestroy($img2);

        // If one image has content and the other is blank, similarity should be very low
        // Check if one is mostly white (>95%) and the other has significant content (>5% non-white)
        $whiteRatio1 = 1.0 - ($nonWhitePixels1 / $totalPixels);
        $whiteRatio2 = 1.0 - ($nonWhitePixels2 / $totalPixels);

        // If one is blank (>95% white) and the other has content (<95% white), similarity is 0
        if (($whiteRatio1 > 0.95 && $whiteRatio2 < 0.95) || ($whiteRatio2 > 0.95 && $whiteRatio1 < 0.95)) {
            // One is blank, the other has content - very low similarity
            return 0.0;
        }

        // If both are blank (>99% white), this is suspicious - might indicate a rendering problem
        // Return low similarity to fail the test, as blank pages shouldn't match
        if ($whiteRatio1 > 0.99 && $whiteRatio2 > 0.99) {
            // Both are essentially blank - this might indicate a problem
            // Return a low similarity to trigger test failure
            return 0.1;
        }

        return $matchingPixels / $totalPixels;
    }

    /**
     * Load image using GD.
     *
     * @return null|GdImage|resource
     */
    private static function loadImage(string $imagePath)
    {
        $imageInfo = getimagesize($imagePath);

        if ($imageInfo === false) {
            return null;
        }

        return match ($imageInfo[2]) {
            IMAGETYPE_PNG  => imagecreatefrompng($imagePath),
            IMAGETYPE_JPEG => imagecreatefromjpeg($imagePath),
            IMAGETYPE_GIF  => imagecreatefromgif($imagePath),
            default        => null,
        };
    }

    /**
     * Check if a PDF page has text content.
     *
     * @param string $pdfPath    Path to PDF file
     * @param int    $pageNumber Page number (1-based)
     *
     * @return bool True if page has text content
     */
    private static function pdfHasTextContent(string $pdfPath, int $pageNumber): bool
    {
        $text = self::extractPdfText($pdfPath, $pageNumber);

        return !empty(trim($text));
    }

    /**
     * Extract text content from a PDF page.
     *
     * @param string $pdfPath    Path to PDF file
     * @param int    $pageNumber Page number (1-based)
     *
     * @return string Extracted text content
     */
    private static function extractPdfText(string $pdfPath, int $pageNumber): string
    {
        // Try using pdftotext if available
        if (self::commandExists('pdftotext')) {
            $command = sprintf(
                'pdftotext -f %d -l %d %s - 2>&1',
                $pageNumber,
                $pageNumber,
                escapeshellarg($pdfPath),
            );
            exec($command, $output, $returnCode);
            $text = implode("\n", $output);

            // Remove excessive whitespace but keep content
            return trim(preg_replace('/\s+/', ' ', $text));
        }

        // Fallback: return empty (assume no text extraction available)
        return '';
    }
}
