<?php
/**
 * Property-based tests for Partition Manager (Properties P20, P21).
 *
 * Uses mt_rand() for randomized input generation, 100 iterations per property.
 * Tests pure functions for partition name generation and date calculations
 * without requiring a live database connection.
 *
 * Feature: production-architecture-overhaul
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/structured_logger.php';
require_once __DIR__ . '/../cron/partition_manager.php';

class PartitionManagerPropertyTest extends TestCase
{
    // =========================================================================
    // Property 20: Partition boundary generation
    // Feature: production-architecture-overhaul, Property 20
    //
    // For any date D, the partition manager should generate partition boundaries
    // where each partition covers exactly one calendar month. The partition name
    // should follow the pattern p{YYYY}_{MM} and the boundary value should be
    // the first day of the next month.
    //
    // **Validates: Requirements 7.1, 7.2**
    // =========================================================================
    public function testProperty20_PartitionBoundaryGeneration(): void
    {
        $iterations = 100;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            // Random year 2020-2030, random month 1-12
            $year = mt_rand(2020, 2030);
            $month = mt_rand(1, 12);
            $day = mt_rand(1, 28); // safe day for all months

            $date = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));

            // Test partition name format: p{YYYY}_{MM}
            $name = generatePartitionName($year, $month);
            $expectedName = sprintf('p%04d_%02d', $year, $month);
            if ($name !== $expectedName) {
                $failures[] = sprintf(
                    'iter=%d: name mismatch for %04d-%02d: expected=%s got=%s',
                    $i, $year, $month, $expectedName, $name
                );
                continue;
            }

            // Test partition boundary: first day of next month
            $boundary = calculatePartitionBoundary($year, $month);

            // Calculate expected boundary
            $firstOfMonth = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            $expectedBoundary = $firstOfMonth->modify('+1 month')->format('Y-m-d');

            if ($boundary !== $expectedBoundary) {
                $failures[] = sprintf(
                    'iter=%d: boundary mismatch for %04d-%02d: expected=%s got=%s',
                    $i, $year, $month, $expectedBoundary, $boundary
                );
                continue;
            }

            // Verify partition covers exactly 1 calendar month
            $partStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            $partEnd = new DateTimeImmutable($boundary);
            $diff = $partStart->diff($partEnd);

            // The difference should be exactly 1 month (0 years, 1 month, 0 days)
            if ($diff->m !== 1 || $diff->d !== 0 || ($diff->y !== 0)) {
                // Handle December → January (year rollover)
                $isYearRollover = ($month === 12 && $diff->y === 0 && $diff->m === 1 && $diff->d === 0);
                if (!$isYearRollover) {
                    $failures[] = sprintf(
                        'iter=%d: partition for %04d-%02d does not cover exactly 1 month: diff=%dy %dm %dd',
                        $i, $year, $month, $diff->y, $diff->m, $diff->d
                    );
                }
            }

            // Verify boundary is the 1st of a month
            $boundaryDate = new DateTimeImmutable($boundary);
            if ((int) $boundaryDate->format('d') !== 1) {
                $failures[] = sprintf(
                    'iter=%d: boundary %s is not the first day of a month',
                    $i, $boundary
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 20 (Partition boundary generation) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 21: Partition lifecycle date calculations
    // Feature: production-architecture-overhaul, Property 21
    //
    // For any current date D, the partition manager should:
    // (a) generate partitions covering months D, D+1, D+2, D+3 (3 months ahead)
    // (b) identify partitions older than D-12 months as candidates for archival
    // The set of "create" partitions and "archive" partitions should never overlap.
    //
    // **Validates: Requirements 7.3, 7.4**
    // =========================================================================
    public function testProperty21_PartitionLifecycleDateCalculations(): void
    {
        $iterations = 100;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            // Random date between 2022-01-01 and 2028-12-28
            $year = mt_rand(2022, 2028);
            $month = mt_rand(1, 12);
            $day = mt_rand(1, 28);

            $now = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));

            // (a) Partitions to create: D, D+1, D+2, D+3
            $toCreate = getPartitionsToCreate($now);

            // Should have exactly 4 partitions
            if (count($toCreate) !== 4) {
                $failures[] = sprintf(
                    'iter=%d: expected 4 create partitions for %s, got %d',
                    $i, $now->format('Y-m-d'), count($toCreate)
                );
                continue;
            }

            // Verify the 4 partitions cover D, D+1, D+2, D+3 months
            $dt = new DateTimeImmutable($now->format('Y-m-01'));
            for ($j = 0; $j < 4; $j++) {
                $expected = $dt->modify("+{$j} months");
                $expectedName = generatePartitionName(
                    (int) $expected->format('Y'),
                    (int) $expected->format('n')
                );
                if ($toCreate[$j]['name'] !== $expectedName) {
                    $failures[] = sprintf(
                        'iter=%d: create partition %d mismatch: expected=%s got=%s',
                        $i, $j, $expectedName, $toCreate[$j]['name']
                    );
                }
            }

            // (b) Archival candidates: older than D-12 months
            $archivalCandidates = getArchivalCandidates($now);

            // Verify all archival candidates are strictly older than D-12 months
            $cutoff = $now->modify('-12 months');
            $cutoffYear = (int) $cutoff->format('Y');
            $cutoffMonth = (int) $cutoff->format('n');

            foreach ($archivalCandidates as $candidate) {
                $cYear = $candidate['year'];
                $cMonth = $candidate['month'];

                $isOlderThanCutoff = ($cYear < $cutoffYear) ||
                    ($cYear === $cutoffYear && $cMonth < $cutoffMonth);

                if (!$isOlderThanCutoff) {
                    $failures[] = sprintf(
                        'iter=%d: archival candidate %s is not older than cutoff %04d-%02d for date %s',
                        $i, $candidate['name'], $cutoffYear, $cutoffMonth, $now->format('Y-m-d')
                    );
                }
            }

            // (c) Create and archive sets should never overlap
            $createNames = array_column($toCreate, 'name');
            $archiveNames = array_column($archivalCandidates, 'name');
            $overlap = array_intersect($createNames, $archiveNames);

            if (!empty($overlap)) {
                $failures[] = sprintf(
                    'iter=%d: create/archive overlap for date %s: %s',
                    $i, $now->format('Y-m-d'), implode(', ', $overlap)
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 21 (Partition lifecycle date calculations) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }
}
