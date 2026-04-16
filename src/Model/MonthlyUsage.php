<?php

declare(strict_types=1);

namespace Krasselt\M2mDataUsage\Model;

class MonthlyUsage
{
    private const GERMAN_MONTHS = [
        'januar' => 1, 'februar' => 2, 'märz' => 3, 'april' => 4,
        'mai' => 5, 'juni' => 6, 'juli' => 7, 'august' => 8,
        'september' => 9, 'oktober' => 10, 'november' => 11, 'dezember' => 12,
    ];

    public function __construct(
        public readonly string $month,
        public readonly float  $usedMb,
        public readonly float  $includedMb,
    ) {
    }

    /**
     * Convert German month name to YYYY-MM format (e.g. "März 2026" -> "2026-03").
     */
    public function yearMonth(): string
    {
        $parts = explode(' ', mb_strtolower(trim($this->month)));
        if (count($parts) < 2) {
            return $this->month;
        }
        $m = self::GERMAN_MONTHS[$parts[0]] ?? 0;
        $y = (int) $parts[1];
        if ($m === 0 || $y === 0) {
            return $this->month;
        }
        return sprintf('%04d-%02d', $y, $m);
    }

    /**
     * Check if this month has completed (is before the current month).
     */
    public function isCompleted(): bool
    {
        $parts = explode(' ', mb_strtolower(trim($this->month)));
        if (count($parts) < 2) {
            return false;
        }
        $m = self::GERMAN_MONTHS[$parts[0]] ?? 0;
        $y = (int) $parts[1];
        if ($m === 0 || $y === 0) {
            return false;
        }
        $now = new \DateTimeImmutable();
        return ($y < (int) $now->format('Y'))
            || ($y === (int) $now->format('Y') && $m < (int) $now->format('n'));
    }

    /**
     * Sort key for ordering months (newest first).
     */
    public function sortKey(): int
    {
        $parts = explode(' ', mb_strtolower(trim($this->month)));
        if (count($parts) < 2) {
            return 0;
        }
        $m = self::GERMAN_MONTHS[$parts[0]] ?? 0;
        $y = (int) $parts[1];
        return $y * 100 + $m;
    }
}
