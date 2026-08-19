<?php

declare(strict_types=1);

namespace Uhifadhi\Ingestion\Service;

use Uhifadhi\Ingestion\Entity\ModuleFeature;
use Uhifadhi\Ingestion\Entity\ModuleFeatureStaging;
use Uhifadhi\Ingestion\Repository\ModuleFeatureRepository;
use Uhifadhi\Ingestion\Repository\ModuleFeatureStagingRepository;
use Uhifadhi\Spatial\Entity\AreaOfInterest;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists a module's raw classified polygons as a dissolved map layer — the generic vector analogue
 * of the Hansen dissolve. The engine hands over thousands of small polygons (one per pixel-cluster),
 * each tagged with a label (e.g. a land-cover class); this stages them, then lets PostGIS do the
 * geometry work (ST_Union → ST_SimplifyPreserveTopology → ST_Multi) per label, replacing this layer's
 * previous {@see ModuleFeature} rows in one transaction. No raw SQL — DQL + the PostGIS bundle only.
 */
final readonly class SpatialFeatureIngestor
{
    private const int BATCH_SIZE = 500;

    /** Fallback Douglas–Peucker tolerance in degrees (~110 m) when the engine sends no hint. A layer's
     *  RIGHT tolerance depends on its grid resolution — too coarse eats sparse patches — so the engine,
     *  which knows the grid, sends `simplify` per dataset and this is only the safety default. */
    private const float SIMPLIFY_DEGREES = 0.001;

    public function __construct(
        private EntityManagerInterface $em,
        private ModuleFeatureStagingRepository $staging,
        private ModuleFeatureRepository $features,
    ) {
    }

    /**
     * @param list<array{label: string, geometry: array<string, mixed>}> $features raw polygons (WGS84)
     */
    public function ingest(AreaOfInterest $area, string $moduleSlug, string $key, array $features, ?float $simplifyDegrees = null): void
    {
        $areaId = (int) $area->getId();

        // A full-AOI feature set decodes to a large array; the worker/CLI may run with a small limit.
        if ('-1' !== ini_get('memory_limit')) {
            ini_set('memory_limit', '1G');
        }

        $this->staging->truncate();
        $batch = 0;
        foreach ($features as $feature) {
            $geometry = $feature['geometry'] ?? null;
            $label = $feature['label'] ?? null;
            if (!\is_array($geometry) || !\is_string($label)) {
                continue;
            }
            $this->em->persist(
                (new ModuleFeatureStaging())
                    ->setLabel($label)
                    ->setGeom((string) json_encode($geometry, \JSON_THROW_ON_ERROR)),
            );
            if (0 === ++$batch % self::BATCH_SIZE) {
                $this->em->flush();
                $this->em->clear();
            }
        }
        $this->em->flush();
        $this->em->clear();

        try {
            $this->dissolve($areaId, $moduleSlug, $key, $simplifyDegrees ?? self::SIMPLIFY_DEGREES);
        } finally {
            $this->staging->truncate();
        }
    }

    /**
     * Dissolve staging into one MultiPolygon per label — entirely in DQL via the PostGIS bundle's
     * functions — and replace this layer's rows as {@see ModuleFeature} entities, in one transaction.
     */
    private function dissolve(int $areaId, string $moduleSlug, string $key, float $tolerance): void
    {
        /** @var list<array{label: string, geojson: string|null}> $rows */
        $rows = $this->em->createQuery(\sprintf(
            'SELECT s.label AS label,
                    ST_AsGeoJSON(ST_Multi(ST_CollectionExtract(ST_MakeValid(
                        ST_SimplifyPreserveTopology(ST_Union(s.geom), :tolerance)), 3))) AS geojson
             FROM %s s
             GROUP BY s.label
             ORDER BY s.label',
            ModuleFeatureStaging::class,
        ))->setParameter('tolerance', $tolerance)->getArrayResult();

        $area = $this->em->find(AreaOfInterest::class, $areaId)
            ?? throw new \RuntimeException(\sprintf('AreaOfInterest %d disappeared mid-run.', $areaId));

        $this->em->wrapInTransaction(function () use ($rows, $area, $moduleSlug, $key): void {
            foreach ($this->features->forLayer($area, $moduleSlug, $key) as $previous) {
                $this->em->remove($previous);
            }
            foreach ($rows as $row) {
                if (!\is_string($row['geojson'])) {
                    continue; // an empty/degenerate class collapses to null — nothing to draw
                }
                $this->em->persist(
                    (new ModuleFeature())
                        ->setAoi($area)
                        ->setModuleSlug($moduleSlug)
                        ->setDatasetKey($key)
                        ->setLabel($row['label'])
                        ->setGeom($row['geojson']),
                );
            }
            $this->em->flush();
        });
    }
}
