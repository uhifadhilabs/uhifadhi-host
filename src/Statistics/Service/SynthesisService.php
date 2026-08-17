<?php

declare(strict_types=1);

namespace App\Statistics\Service;

use App\Ingestion\Entity\Dataset;
use App\Ingestion\Enum\DatasetKind;
use App\Ingestion\Repository\DatasetRepository;
use App\Spatial\Entity\AreaOfInterest;
use App\Spatial\Module\ModuleDefinition;
use App\Spatial\Repository\AreaOfInterestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The Q6 synthesis: one headline indicator per research objective, DERIVED from the other
 * modules' stored datasets — no engine run, no new source. refresh() materializes the result
 * as the statistics module's `synthesis` + `provenance` dataframes in the generic store, so
 * every generic tab (dataframe, explore, chips) works unchanged. Provenance derives from the
 * registered ModuleDefinitions' method captions, so a new module documents itself here.
 */
final readonly class SynthesisService
{
    public function __construct(
        private DatasetRepository $datasets,
        private AreaOfInterestRepository $areas,
        private EntityManagerInterface $em,
        /** @var iterable<ModuleDefinition> */
        #[AutowireIterator('app.module')]
        private iterable $definitions,
    ) {
    }

    /**
     * @return list<array{string, string, float|null, string, string}> (module, indicator, value, unit, source)
     */
    public function indicators(AreaOfInterest $area): array
    {
        $rows = [];

        if (null !== $epochs = $this->rows($area, 'settlement', 'builtup_epoch')) {
            $last = end($epochs);
            $rows[] = ['Settlement', \sprintf('Built-up area %s', $last[0]), (float) $last[1], 'km²', $this->source($area, 'settlement', 'builtup_epoch')];
            $rows[] = ['Settlement', \sprintf('Built-up growth since %s', $epochs[0][0]), (float) $last[4], \sprintf('× the %s level', $epochs[0][0]), $this->source($area, 'settlement', 'builtup_epoch')];
        }
        if (null !== $classes = $this->rows($area, 'landcover', 'landcover_class')) {
            $top = $classes[0];
            $rows[] = ['Land cover', \sprintf('%s share of the area', $top[0]), (float) $top[2], '%', $this->source($area, 'landcover', 'landcover_class')];
        }
        if (null !== $phenology = $this->rows($area, 'vegetation', 'phenology_16day')) {
            $peak = max(array_map(static fn (array $row): float => (float) $row[2], $phenology));
            $rows[] = ['Vegetation', 'Peak-season NDVI (spatial median)', round($peak, 3), '0–1 index', $this->source($area, 'vegetation', 'phenology_16day')];
        }
        if (null !== $sdm = $this->rows($area, 'wildlife', 'sdm_performance')) {
            foreach ($sdm as $row) {
                if ('native' === ($row[2] ?? null) && null !== ($row[4] ?? null)) {
                    $rows[] = ['Wildlife', \sprintf('%s habitat model AUC', (string) $row[1]), (float) $row[4], '0.5–1 (1 = perfect)', $this->source($area, 'wildlife', 'sdm_performance')];
                    break;
                }
            }
        }
        if (null !== $loss = $this->rows($area, 'forest', 'forest_loss_year')) {
            $last = end($loss);
            $rows[] = ['Forest', \sprintf('Tree cover lost %s–%s', $loss[0][0], $last[0]), (float) $last[2], 'ha', $this->source($area, 'forest', 'forest_loss_year')];
        }
        if (null !== $stats = $this->rows($area, 'roads', 'roads_stats')) {
            $byMetric = array_column($stats, 1, 0);
            $rows[] = ['Roads', 'Mapped road network', (float) ($byMetric['total_km'] ?? 0), 'km', $this->source($area, 'roads', 'roads_stats')];
            $rows[] = ['Roads', 'More than 2 km from any road', (float) ($byMetric['remote_pct_gt2km'] ?? 0), '%', $this->source($area, 'roads', 'roads_stats')];
        }
        $rows[] = ['Cross-check', 'Area from the boundary polygon', round($this->areas->stAreaKm2(['id' => $area->getId()])), 'km²', 'boundary geometry · ST_Area'];

        return $rows;
    }

    /**
     * @return list<array{string, string, string, string}> (module, dataset, licence, key_uncertainty)
     */
    public function provenance(): array
    {
        $rows = [];
        foreach ($this->definitions as $definition) {
            $caption = $definition->methodCaption();
            if (null === $caption) {
                continue;
            }
            $source = array_column($caption->source, 'value', 'label');
            $rows[] = [
                $definition->slug(),
                $source['Dataset'] ?? $source['Sightings'] ?? '—',
                $source['Licence'] ?? '—',
                $source['Caveat'] ?? strip_tags($caption->sourceNote ?? '—'),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return $rows;
    }

    /** Materialize both dataframes into the generic store (the statistics module's data). */
    public function refresh(AreaOfInterest $area): void
    {
        $this->datasets->upsert($area, 'statistics', 'synthesis')
            ->setKind(DatasetKind::Table)
            ->setColumns(['module', 'indicator', 'value', 'unit', 'source'])
            ->setRows($this->indicators($area))
            ->setSource('derived from the other modules\' datasets');
        $this->datasets->upsert($area, 'statistics', 'provenance')
            ->setKind(DatasetKind::Table)
            ->setColumns(['module', 'dataset', 'licence', 'key_uncertainty'])
            ->setRows($this->provenance())
            ->setSource('module method captions');
        $this->em->flush();
    }

    /** @return non-empty-list<array<int, mixed>>|null */
    private function rows(AreaOfInterest $area, string $module, string $key): ?array
    {
        $rows = $this->dataset($area, $module, $key)?->getRows();

        return \is_array($rows) && [] !== $rows ? array_values($rows) : null;
    }

    private function source(AreaOfInterest $area, string $module, string $key): string
    {
        return (string) ($this->dataset($area, $module, $key)?->getSource() ?? '');
    }

    private function dataset(AreaOfInterest $area, string $module, string $key): ?Dataset
    {
        return $this->datasets->findOneFor($area, $module, $key);
    }
}
