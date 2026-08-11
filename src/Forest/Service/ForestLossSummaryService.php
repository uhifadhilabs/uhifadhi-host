<?php

declare(strict_types=1);

namespace App\Forest\Service;

use App\Forest\Repository\ForestLossYearRepository;
use App\Spatial\Entity\AreaOfInterest;

/**
 * The per-area annual forest-loss summary: the year series (each coloured by the
 * Hansen year ramp) plus the headline figures (total, span, worst year). Shared
 * by the area Overview and the Forest-loss module page so both read one source.
 */
final readonly class ForestLossSummaryService
{
    public function __construct(
        private ForestLossYearRepository $loss,
        private LossYearPaletteService $palette,
    ) {
    }

    /**
     * @return array{
     *     lossByYear: list<array{year: int, ha: float, color: string}>,
     *     maxHa: float, totalHa: float, yearFrom: ?int, yearTo: ?int,
     *     worstYear: ?int, worstHa: float
     * }
     */
    public function forArea(AreaOfInterest $area): array
    {
        return $this->summarize($this->loss->findBy(['aoi' => $area], ['year' => 'ASC']));
    }

    /**
     * The pure summary over an ascending-by-year row set (separated so it is
     * unit-testable without a repository).
     *
     * @param list<\App\Forest\Entity\ForestLossYear> $rows
     *
     * @return array{
     *     lossByYear: list<array{year: int, ha: float, color: string}>,
     *     maxHa: float, totalHa: float, yearFrom: ?int, yearTo: ?int,
     *     worstYear: ?int, worstHa: float
     * }
     */
    public function summarize(array $rows): array
    {
        $lossByYear = [];
        $totalHa = 0.0;
        $maxHa = 0.0;
        $worstYear = null;
        $worstHa = 0.0;
        foreach ($rows as $row) {
            $year = (int) $row->getYear();
            $ha = $row->getAreaHa() ?? 0.0;
            $totalHa += $ha;
            $maxHa = max($maxHa, $ha);
            // "Worst year" ignores 2001 — that bar is the known Hansen baseline artifact.
            if (2001 !== $year && $ha > $worstHa) {
                $worstHa = $ha;
                $worstYear = $year;
            }
            $lossByYear[] = ['year' => $year, 'ha' => $ha, 'color' => $this->palette->colorFor($year)];
        }

        return [
            'lossByYear' => $lossByYear,
            'maxHa' => $maxHa,
            'totalHa' => $totalHa,
            'yearFrom' => [] !== $rows ? (int) $rows[array_key_first($rows)]->getYear() : null,
            'yearTo' => [] !== $rows ? (int) $rows[array_key_last($rows)]->getYear() : null,
            'worstYear' => $worstYear,
            'worstHa' => $worstHa,
        ];
    }
}
