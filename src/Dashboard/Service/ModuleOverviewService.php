<?php

declare(strict_types=1);

namespace App\Dashboard\Service;

use App\Ingestion\Entity\Dataset;
use App\Ingestion\Entity\DatasetRun;
use App\Spatial\Module\Kpi;

/**
 * The GENERIC Overview KPI derivation — used when a module's definition supplies no KPIs of its own:
 * headline figures read straight from the module's first tabular dataset (dominant row, row count,
 * a mean of a density-ish column) plus the last run. Works for any class-shaped dataframe; a module
 * wanting richer figures overrides ModuleDefinition::kpis() in its own context.
 */
final readonly class ModuleOverviewService
{
    /**
     * @return list<Kpi>
     */
    public function deriveKpis(?Dataset $table, ?DatasetRun $lastRun): array
    {
        $kpis = [];

        if (null !== $table) {
            $columns = $table->getColumns() ?? [];
            $rows = $table->getRows() ?? [];
            $at = array_flip($columns);

            if ([] !== $rows && isset($at['class'], $at['area_km2'], $at['pct'])) {
                $dominant = $rows[0];
                $maxArea = -\INF;
                foreach ($rows as $row) {
                    $area = (float) ($row[$at['area_km2']] ?? 0);
                    if ($area > $maxArea) {
                        $maxArea = $area;
                        $dominant = $row;
                    }
                }
                $kpis[] = new Kpi('OV·01', (string) ($dominant[$at['class']] ?? ''), (string) round((float) ($dominant[$at['pct']] ?? 0)), '%', 'dominant cover');
                $kpis[] = new Kpi('OV·02', 'Classes', (string) \count($rows), '', $table->getSource());

                if (isset($at['patch_density'])) {
                    $sum = 0.0;
                    $n = 0;
                    foreach ($rows as $row) {
                        if (is_numeric($row[$at['patch_density']] ?? null)) {
                            $sum += (float) $row[$at['patch_density']];
                            ++$n;
                        }
                    }
                    if ($n > 0) {
                        $kpis[] = new Kpi('OV·03', 'Fragmentation', (string) round($sum / $n, 1), 'pd', 'patches / 100 ha');
                    }
                }
            }
        }

        if ([] !== $kpis || null !== $lastRun) {
            $kpis[] = new Kpi(
                'OV·04',
                'Last run',
                null !== $lastRun ? $lastRun->getStatus() : '—',
                '',
                $lastRun?->getFinishedAt()?->format('M j, H:i') ?? 'not ingested',
            );
        }

        return $kpis;
    }
}
