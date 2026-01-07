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
namespace PXP\PDF\Fpdf\Utils\Debug;

use const ARRAY_FILTER_USE_KEY;
use function abs;
use function array_column;
use function array_filter;
use function array_sum;
use function count;
use function end;
use function floor;
use function gc_collect_cycles;
use function get_debug_type;
use function ini_get;
use function log;
use function max;
use function memory_get_peak_usage;
use function memory_get_usage;
use function microtime;
use function min;
use function reset;
use function round;
use function serialize;
use function sprintf;
use function str_pad;
use function str_starts_with;
use function strlen;
use function strtolower;
use function trim;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Memory profiler for tracking memory usage during PDF operations.
 * Provides detailed insights into memory consumption patterns.
 */
final class MemoryProfiler
{
    /** @var array<string, array{timestamp: float, memory: int, peak: int}> */
    private array $checkpoints = [];

    /** @var array<string, int> */
    private array $snapshotCounts = [];
    private readonly int $startMemory;
    private readonly int $startPeak;
    private readonly float $startTime;

    public function __construct(private readonly ?LoggerInterface $logger = new NullLogger, private readonly bool $enableDetailed = false)
    {
        $this->startMemory = memory_get_usage(true);
        $this->startPeak   = memory_get_peak_usage(true);
        $this->startTime   = microtime(true);

        $this->checkpoint('start', 'Profiling started');
    }

    /**
     * Record a memory checkpoint with a label.
     */
    public function checkpoint(string $label, ?string $message = null): void
    {
        $memory    = memory_get_usage(true);
        $peak      = memory_get_peak_usage(true);
        $timestamp = microtime(true);

        $this->checkpoints[$label] = [
            'timestamp' => $timestamp,
            'memory'    => $memory,
            'peak'      => $peak,
        ];

        if ($message !== null) {
            $this->logger->debug($message, [
                'checkpoint'       => $label,
                'memory'           => $this->formatBytes($memory),
                'peak'             => $this->formatBytes($peak),
                'delta_from_start' => $this->formatBytes($memory - $this->startMemory),
            ]);
        }
    }

    /**
     * Take a snapshot at a specific point in code execution.
     * Useful for tracking memory in loops or repeated operations.
     */
    public function snapshot(string $context, array $metadata = []): array
    {
        $memory = memory_get_usage(true);
        $peak   = memory_get_peak_usage(true);

        if (!isset($this->snapshotCounts[$context])) {
            $this->snapshotCounts[$context] = 0;
        }
        $this->snapshotCounts[$context]++;

        $snapshot = [
            'context'          => $context,
            'count'            => $this->snapshotCounts[$context],
            'memory'           => $memory,
            'memory_formatted' => $this->formatBytes($memory),
            'peak'             => $peak,
            'peak_formatted'   => $this->formatBytes($peak),
            'timestamp'        => microtime(true),
            'metadata'         => $metadata,
        ];

        if ($this->enableDetailed) {
            $snapshot['memory_detailed'] = $this->getDetailedMemoryInfo();
        }

        return $snapshot;
    }

    /**
     * Get detailed memory information including system-level stats.
     */
    public function getDetailedMemoryInfo(): array
    {
        $info = [
            'current_usage'      => memory_get_usage(false),
            'current_usage_real' => memory_get_usage(true),
            'peak_usage'         => memory_get_peak_usage(false),
            'peak_usage_real'    => memory_get_peak_usage(true),
            'limit'              => ini_get('memory_limit'),
        ];

        // Get memory usage percentage
        $limit = $this->parseMemoryLimit(ini_get('memory_limit'));

        if ($limit > 0) {
            $info['usage_percentage']    = round(($info['current_usage_real'] / $limit) * 100, 2);
            $info['remaining']           = $limit - $info['current_usage_real'];
            $info['remaining_formatted'] = $this->formatBytes($info['remaining']);
        }

        return $info;
    }

    /**
     * Get memory delta between two checkpoints.
     *
     * @return array<string, float|int|string>
     */
    public function getDelta(string $fromCheckpoint, string $toCheckpoint): array
    {
        if (!isset($this->checkpoints[$fromCheckpoint]) || !isset($this->checkpoints[$toCheckpoint])) {
            throw new InvalidArgumentException('Invalid checkpoint labels');
        }

        $from = $this->checkpoints[$fromCheckpoint];
        $to   = $this->checkpoints[$toCheckpoint];

        return [
            'memory_delta'           => $to['memory'] - $from['memory'],
            'memory_delta_formatted' => $this->formatBytes($to['memory'] - $from['memory']),
            'peak_delta'             => $to['peak'] - $from['peak'],
            'peak_delta_formatted'   => $this->formatBytes($to['peak'] - $from['peak']),
            'time_delta'             => $to['timestamp'] - $from['timestamp'],
            'time_delta_formatted'   => round(($to['timestamp'] - $from['timestamp']) * 1000, 2) . ' ms',
        ];
    }

    /**
     * Analyze memory allocation patterns from snapshots.
     */
    public function analyzePattern(string $context): ?array
    {
        $snapshots = array_filter($this->checkpoints, static fn ($cp): bool => str_starts_with((string) $cp, $context), ARRAY_FILTER_USE_KEY);

        if (count($snapshots) < 2) {
            return null;
        }

        $memories = array_column($snapshots, 'memory');
        $diffs    = [];
        $counter  = count($memories);

        for ($i = 1; $i < $counter; $i++) {
            $diffs[] = $memories[$i] - $memories[$i - 1];
        }

        return [
            'context'                => $context,
            'sample_count'           => count($snapshots),
            'avg_delta'              => array_sum($diffs) / count($diffs),
            'avg_delta_formatted'    => $this->formatBytes((int) (array_sum($diffs) / count($diffs))),
            'max_delta'              => max($diffs),
            'max_delta_formatted'    => $this->formatBytes(max($diffs)),
            'min_delta'              => min($diffs),
            'min_delta_formatted'    => $this->formatBytes(min($diffs)),
            'total_growth'           => end($memories) - reset($memories),
            'total_growth_formatted' => $this->formatBytes(end($memories) - reset($memories)),
        ];
    }

    /**
     * Get a summary report of all memory activity.
     *
     * @return array<string, array<int|string, array<string, float|string>|int>|float|string>
     */
    public function getSummary(): array
    {
        $currentMemory = memory_get_usage(true);
        $currentPeak   = memory_get_peak_usage(true);
        $duration      = microtime(true) - $this->startTime;

        $checkpointList = [];

        foreach ($this->checkpoints as $label => $data) {
            $checkpointList[] = [
                'label'     => $label,
                'timestamp' => $data['timestamp'],
                'elapsed'   => round(($data['timestamp'] - $this->startTime) * 1000, 2) . ' ms',
                'memory'    => $this->formatBytes($data['memory']),
                'peak'      => $this->formatBytes($data['peak']),
            ];
        }

        return [
            'start_memory'      => $this->formatBytes($this->startMemory),
            'start_peak'        => $this->formatBytes($this->startPeak),
            'current_memory'    => $this->formatBytes($currentMemory),
            'current_peak'      => $this->formatBytes($currentPeak),
            'memory_growth'     => $this->formatBytes($currentMemory - $this->startMemory),
            'peak_growth'       => $this->formatBytes($currentPeak - $this->startPeak),
            'duration_ms'       => round($duration * 1000, 2),
            'checkpoints'       => $checkpointList,
            'snapshot_contexts' => $this->snapshotCounts,
        ];
    }

    /**
     * Print a formatted memory report to stdout.
     */
    public function printReport(): void
    {
        $summary = $this->getSummary();

        print "\n";
        print "═══════════════════════════════════════════════════════════════\n";
        print "                     MEMORY PROFILE REPORT                     \n";
        print "═══════════════════════════════════════════════════════════════\n";
        print "\n";

        print "Overall Statistics:\n";
        print "  Start Memory:     {$summary['start_memory']}\n";
        print "  Current Memory:   {$summary['current_memory']}\n";
        print "  Memory Growth:    {$summary['memory_growth']}\n";
        print "  Peak Growth:      {$summary['peak_growth']}\n";
        print "  Duration:         {$summary['duration_ms']} ms\n";
        print "\n";

        if (!empty($summary['checkpoints'])) {
            print "Checkpoints:\n";

            foreach ($summary['checkpoints'] as $cp) {
                print sprintf(
                    "  [%s] %s - Memory: %s, Peak: %s\n",
                    $cp['elapsed'],
                    str_pad((string) $cp['label'], 30),
                    str_pad((string) $cp['memory'], 12),
                    $cp['peak'],
                );
            }
            print "\n";
        }

        if (!empty($summary['snapshot_contexts'])) {
            print "Snapshot Contexts:\n";

            foreach ($summary['snapshot_contexts'] as $context => $count) {
                print "  {$context}: {$count} snapshots\n";
            }
            print "\n";
        }

        $detailed = $this->getDetailedMemoryInfo();

        if (isset($detailed['usage_percentage'])) {
            print "Memory Limit Analysis:\n";
            print "  Limit:            {$detailed['limit']}\n";
            print "  Current Usage:    {$detailed['usage_percentage']}%\n";
            print "  Remaining:        {$detailed['remaining_formatted']}\n";
            print "\n";
        }

        print "═══════════════════════════════════════════════════════════════\n";
        print "\n";
    }

    /**
     * Force garbage collection and report the effect.
     */
    public function forceGC(string $context = 'manual'): array
    {
        $beforeMemory = memory_get_usage(true);
        memory_get_peak_usage(true);

        $cycles = gc_collect_cycles();

        $afterMemory = memory_get_usage(true);
        memory_get_peak_usage(true);

        $result = [
            'context'                => $context,
            'cycles_collected'       => $cycles,
            'memory_freed'           => $beforeMemory - $afterMemory,
            'memory_freed_formatted' => $this->formatBytes($beforeMemory - $afterMemory),
            'before_memory'          => $this->formatBytes($beforeMemory),
            'after_memory'           => $this->formatBytes($afterMemory),
        ];

        $this->logger->debug('Garbage collection executed', $result);

        return $result;
    }

    /**
     * Monitor variable memory consumption.
     * CAUTION: This serializes the variable which can be expensive.
     *
     * @return array<string, int|string>
     */
    public function measureVariable(string $name, mixed $variable): array
    {
        $serialized = serialize($variable);
        $size       = strlen($serialized);
        unset($serialized);

        return [
            'name'           => $name,
            'type'           => get_debug_type($variable),
            'size'           => $size,
            'size_formatted' => $this->formatBytes($size),
        ];
    }

    /**
     * Get size of an array or object property recursively.
     * WARNING: Can be slow for large structures.
     */
    public function deepSize(mixed $value): int
    {
        return strlen(serialize($value));
    }

    /**
     * Format bytes into human-readable string.
     */
    public function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units    = ['B', 'KB', 'MB', 'GB', 'TB'];
        $negative = $bytes < 0;
        $bytes    = abs($bytes);

        $pow = floor(($bytes !== 0 ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= 1024 ** $pow;

        $formatted = round($bytes, 2) . ' ' . $units[$pow];

        return $negative ? '-' . $formatted : $formatted;
    }

    /**
     * Parse memory_limit ini value to bytes.
     */
    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return -1; // Unlimited
        }

        $limit = trim($limit);
        $last  = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;

        switch ($last) {
            case 'g':
                $value *= 1024;

                // no break
            case 'm':
                $value *= 1024;

                // no break
            case 'k':
                $value *= 1024;
        }

        return $value;
    }
}
