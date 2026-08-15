<?php

declare(strict_types=1);

namespace App\Dashboard\Service;

use App\Ingestion\Entity\Dataset;

/**
 * The headline KPI figures for a class-composition module (today, land cover), derived from the
 * module's `*_class` dataframe. The Overview's CHARTS are the module's configured visualizations
 * (rendered separately) — not this service. Returns null for a table that isn't class-shaped
 * (no class/area_km2/pct columns), so the caller falls back to the scaffold.
 */
final readonly class ModuleOverviewService
{
    /**
     * @return array{dominantClass: string, dominantPct: float, classCount: int, fragmentation: float|null}|null
     */
    public function cockpit(Dataset $table): ?array
    {
        $columns = $table->getColumns() ?? [];
        $rows = $table->getRows() ?? [];
        $at = array_flip($columns);
        if ([] === $rows || !isset($at['class'], $at['area_km2'], $at['pct'])) {
            return null;
        }

        $dominant = $rows[0];
        $maxArea = -\INF;
        $patchDensitySum = 0.0;
        $patchDensityN = 0;
        foreach ($rows as $row) {
            $area = (float) ($row[$at['area_km2']] ?? 0);
            if ($area > $maxArea) {
                $maxArea = $area;
                $dominant = $row;
            }
            if (isset($at['patch_density']) && is_numeric($row[$at['patch_density']] ?? null)) {
                $patchDensitySum += (float) $row[$at['patch_density']];
                ++$patchDensityN;
            }
        }

        return [
            'dominantClass' => (string) ($dominant[$at['class']] ?? ''),
            'dominantPct' => (float) ($dominant[$at['pct']] ?? 0),
            'classCount' => \count($rows),
            'fragmentation' => $patchDensityN > 0 ? round($patchDensitySum / $patchDensityN, 1) : null,
        ];
    }
}
