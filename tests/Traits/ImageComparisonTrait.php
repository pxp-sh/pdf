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
use function abs;
use function escapeshellarg;
use function exec;
use function extension_loaded;
use function file_exists;
use function filesize;
use function getimagesize;
use function imagecolorat;
use function imagecreatefromgif;
use function imagecreatefromjpeg;
use function imagecreatefrompng;
use function imagedestroy;
use function imagesx;
use function imagesy;
use function implode;
use function is_readable;
use function max;
use function preg_match;
use function sprintf;
use function str_contains;
use function uniqid;
use RuntimeException;

trait ImageComparisonTrait
{
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
            // Return a low similarity to fail the test
            return 0.1;
        }

        return $matchingPixels / $totalPixels;
    }

    /**
     * Load image using GD.
     *
     * @return null|GdImage|resource
     */
    private static function loadImage(string $imagePath): null|false|\GdImage
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
}
