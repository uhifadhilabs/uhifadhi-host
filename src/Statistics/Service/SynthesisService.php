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
            $source = $this->source($area, 'settlement', 'builtup_epoch');
            // The first and latest epochs side by side — the growth is self-evident,
            // no ratio jargon needed.
            $rows[] = ['Settlement', \sprintf('Built-up area %s', $epochs[0][0]), (float) $epochs[0][1], 'km²', $source];
            $rows[] = ['Settlement', \sprintf('Built-up area %s', $last[0]), (float) $last[1], 'km²', $source];
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
        if (null !== $structure = $this->rows($area, 'structure', 'structure_stats')) {
            $byMetric = array_column($structure, 1, 0);
            if (isset($byMetric['pearson_r'])) {
                $rows[] = ['Structure', 'Canopy–biomass agreement (Pearson r)', (float) $byMetric['pearson_r'], '−1–1 (1 = perfect)', $this->source($area, 'structure', 'structure_stats')];
            }
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

    /** Materialize the synthesis dataframes into the generic store (the statistics module's data). */
    public function refresh(AreaOfInterest $area): void
    {
        $this->store($area, 'synthesis', ['module', 'indicator', 'value', 'unit', 'source'],
            $this->indicators($area), 'derived from the other modules\' datasets');
        $this->store($area, 'provenance', ['module', 'dataset', 'licence', 'key_uncertainty'],
            $this->provenance(), 'module method captions');

        // Chart-ready cross-module frames — the scorecard's whole point is seeing every
        // module's headline shape in one place. Each is a 2-column projection of a source
        // module's dataframe, created only when that source exists.
        foreach ($this->chartFrames($area) as $key => [$columns, $rows, $source]) {
            $this->store($area, $key, $columns, $rows, $source);
        }
        $this->em->flush();
    }

    /**
     * @return array<string, array{list<string>, list<array<int, mixed>>, string}>
     */
    private function chartFrames(AreaOfInterest $area): array
    {
        $frames = [];
        if (null !== $epochs = $this->rows($area, 'settlement', 'builtup_epoch')) {
            $frames['builtup_trend'] = [['year', 'built_km2'],
                array_map(static fn (array $r): array => [$r[0], $r[1]], $epochs),
                $this->source($area, 'settlement', 'builtup_epoch')];
        }
        if (null !== $loss = $this->rows($area, 'forest', 'forest_loss_year')) {
            $frames['forest_loss_trend'] = [['year', 'ha'],
                array_map(static fn (array $r): array => [$r[0], $r[1]], $loss),
                $this->source($area, 'forest', 'forest_loss_year')];
        }
        if (null !== $phenology = $this->rows($area, 'vegetation', 'phenology_16day')) {
            $frames['greenness_curve'] = [['doy', 'ndvi_median'],
                array_map(static fn (array $r): array => [$r[1], $r[2]], $phenology),
                $this->source($area, 'vegetation', 'phenology_16day')];
        }
        if (null !== $classes = $this->rows($area, 'landcover', 'landcover_class')) {
            $frames['landcover_mix'] = [['class', 'pct'],
                array_map(static fn (array $r): array => [$r[0], $r[2]], $classes),
                $this->source($area, 'landcover', 'landcover_class')];
        }
        if (null !== $roads = $this->rows($area, 'roads', 'roads_by_class')) {
            $frames['road_classes'] = [['class', 'length_km'],
                array_map(static fn (array $r): array => [$r[0], $r[1]], \array_slice($roads, 0, 8)),
                $this->source($area, 'roads', 'roads_by_class')];
        }
        if (null !== $sdm = $this->rows($area, 'wildlife', 'sdm_performance')) {
            $rows = [];
            foreach ($sdm as $r) {
                if (null !== ($r[4] ?? null)) {
                    $rows[] = [$r[1], $r[4]];
                }
            }
            if ([] !== $rows) {
                $frames['sdm_scores'] = [['species', 'auc'], $rows,
                    $this->source($area, 'wildlife', 'sdm_performance')];
            }
        }

        return $frames;
    }

    /**
     * @param list<string>              $columns
     * @param list<array<int, mixed>>   $rows
     */
    private function store(AreaOfInterest $area, string $key, array $columns, array $rows, string $source): void
    {
        $this->datasets->upsert($area, 'statistics', $key)
            ->setKind(DatasetKind::Table)
            ->setColumns($columns)
            ->setRows($rows)
            ->setSource($source);
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
