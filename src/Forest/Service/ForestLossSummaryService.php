<?php

declare(strict_types=1);

namespace Uhifadhi\Forest\Service;

use Uhifadhi\Ingestion\Repository\DatasetRepository;
use Uhifadhi\Spatial\Entity\AreaOfInterest;

/**
 * The per-area annual forest-loss summary: the year series (each coloured by the Hansen year ramp)
 * plus the headline figures (total, span, worst year). Reads the module's `forest_loss_year` series
 * from the GENERIC dataset store — the engine writes it there — so the area Overview, the module
 * KPIs and the register all read one source.
 */
final readonly class ForestLossSummaryService
{
    public function __construct(
        private DatasetRepository $datasets,
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
        $rows = $this->datasets->findOneFor($area, 'forest', 'forest_loss_year')?->getRows() ?? [];

        return $this->summarize($rows);
    }

    /**
     * The pure summary over (year, ha, …) rows sorted ascending by year (separated so it is
     * unit-testable without a repository).
     *
     * @param list<list<scalar|null>> $rows
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
            $year = (int) ($row[0] ?? 0);
            $ha = is_numeric($row[1] ?? null) ? (float) $row[1] : 0.0;
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
            'yearFrom' => [] !== $lossByYear ? $lossByYear[array_key_first($lossByYear)]['year'] : null,
            'yearTo' => [] !== $lossByYear ? $lossByYear[array_key_last($lossByYear)]['year'] : null,
            'worstYear' => $worstYear,
            'worstHa' => $worstHa,
        ];
    }
}
