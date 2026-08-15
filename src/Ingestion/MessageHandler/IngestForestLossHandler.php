<?php

declare(strict_types=1);

namespace App\Ingestion\MessageHandler;

use App\Forest\Entity\ForestLossYear;
use App\Forest\Repository\ForestLossYearRepository;
use App\Ingestion\Entity\DatasetRun;
use App\Ingestion\Entity\HansenLossPolygon;
use App\Ingestion\Enum\DatasetKind;
use App\Ingestion\Message\IngestForestLoss;
use App\Ingestion\Repository\DatasetRepository;
use App\Ingestion\Repository\HansenLossPolygonRepository;
use App\Ingestion\Service\TileSourceInterface;
use App\Spatial\Entity\AreaOfInterest;
use App\Spatial\Repository\AreaOfInterestRepository;
use Doctrine\ORM\EntityManagerInterface;
use FundiStadi\GDALBundle\Process\GdalRunner;
use FundiStadi\GDALBundle\Tool\Gdalwarp;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The FOREST module's loss ingestion. Hansen GFC is its (first) data SOURCE — the source-specific
 * pieces are the adapters ({@see TileSourceInterface} → HansenTileService, the dn-coded
 * {@see HansenLossPolygon} staging); the module owns the pipeline and its outputs. GDAL tools touch
 * FILES ONLY; every database write goes through the ORM (entities) or DQL with the PostGIS bundle's
 * functions — nothing writes SQL by hand:
 *
 *   gdalwarp (clip granule to AOI, nodata 255)      — file → file
 *   gdal raster polygonize → GeoJSON                — file → file (C++, no Python)
 *   batched ORM persist → HansenLossPolygon staging — the bundle's geometry type
 *   DQL dissolve (ST_Union → ST_MakeValid → …)      — hydrates per-year rows
 *   ForestLossYear entities, replace per (aoi, source), one transaction
 *
 * Every run writes a DatasetRun provenance row. nodata=255 encodes a real data
 * bug: -dstnodata 0 silently turns no-loss pixels into "year 2001".
 */
#[AsMessageHandler]
final class IngestForestLossHandler
{
    private const int BATCH_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GdalRunner $gdal,
        private readonly TileSourceInterface $tiles,
        private readonly AreaOfInterestRepository $areas,
        private readonly ForestLossYearRepository $lossYears,
        private readonly HansenLossPolygonRepository $staging,
        private readonly DatasetRepository $datasets,
    ) {
    }

    public function __invoke(IngestForestLoss $message): void
    {
        $run = (new DatasetRun())
            ->setDataset('hansen_gfc_lossyear')
            ->setAoi($this->areas->find($message->aoiId))
            ->setParams([
                'aoiId' => $message->aoiId,
                'version' => $message->version,
                'source' => $message->source,
                'simplifyDegrees' => $message->simplifyDegrees,
            ])
            ->setStartedAt(new \DateTimeImmutable());
        $this->em->persist($run);
        $this->em->flush();
        $runId = (int) $run->getId();

        $workdir = sys_get_temp_dir().'/ingest-hansen-'.bin2hex(random_bytes(4));
        mkdir($workdir);

        try {
            $report = $this->pipeline($message, $workdir);
            $this->finishRun($runId, static function (DatasetRun $run) use ($report): void {
                $run->setStatus(DatasetRun::STATUS_SUCCEEDED)->setReport($report);
            });
        } catch (\Throwable $e) {
            $this->finishRun($runId, static function (DatasetRun $run) use ($e): void {
                $run->setStatus(DatasetRun::STATUS_FAILED)->setError($e->getMessage());
            });

            throw $e;
        } finally {
            array_map(unlink(...), glob($workdir.'/*') ?: []);
            @rmdir($workdir);
        }
    }

    /**
     * The batched staging load clears the EntityManager, detaching anything
     * loaded before it — so the run is always re-fetched fresh by id.
     *
     * @param callable(DatasetRun): void $mutate
     */
    private function finishRun(int $runId, callable $mutate): void
    {
        $run = $this->em->find(DatasetRun::class, $runId);
        if (null === $run) {
            return;
        }
        $mutate($run);
        $run->setFinishedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    /**
     * @return array<string, mixed> the per-year report stored on the DatasetRun
     */
    private function pipeline(IngestForestLoss $message, string $workdir): array
    {
        $aoi = $this->areas->find($message->aoiId)
            ?? throw new \RuntimeException(\sprintf('AreaOfInterest %d not found.', $message->aoiId));
        $geoJson = $aoi->getGeom()
            ?? throw new \RuntimeException(\sprintf('AreaOfInterest %d has no geometry.', $message->aoiId));

        // The cutline needs a vector FILE; wrap the bare geometry as a Feature.
        $cutline = $workdir.'/aoi.geojson';
        file_put_contents($cutline, (string) json_encode([
            'type' => 'Feature',
            'properties' => [],
            'geometry' => json_decode($geoJson, true, 512, \JSON_THROW_ON_ERROR),
        ]));

        [$minX, $minY, $maxX, $maxY] = self::bbox($geoJson);
        $sources = $this->tiles->sources($minX, $minY, $maxX, $maxY, $message->version);

        $this->staging->truncate();
        foreach ($sources as $i => $source) {
            // Clip. nodata MUST be 255: 0 means "no loss" and would otherwise be
            // silently rewritten to 1 (= year 2001).
            $clip = \sprintf('%s/clip_%d.tif', $workdir, $i);
            $this->gdal->run(
                Gdalwarp::create()
                    ->cutline($cutline)->cropToCutline()
                    ->dstNodata(255)->outputType('Byte')
                    ->argv($source, $clip),
            );

            // Pixels → polygons, to a FILE (compiled C++ subcommand; the database
            // is never touched by an external tool).
            $vectors = \sprintf('%s/loss_%d.geojson', $workdir, $i);
            $this->gdal->run([
                'gdal', 'raster', 'polygonize', '--quiet', '--overwrite',
                '-f', 'GeoJSON', '--band', '1', '--attribute-name', 'dn',
                '-i', $clip, '-o', $vectors,
            ]);

            $this->loadStaging($vectors);
        }

        try {
            return $this->dissolve($message);
        } finally {
            $this->staging->truncate();
        }
    }

    /**
     * Streams the polygonized features into the staging entity, batched — every
     * geometry goes through the bundle's polygon type (ST_GeomFromGeoJSON).
     */
    private function loadStaging(string $geoJsonFile): void
    {
        // A full-AOI feature collection decodes to a large array; the worker/CLI
        // context may run with a small default memory_limit.
        if ('-1' !== ini_get('memory_limit')) {
            ini_set('memory_limit', '1G');
        }

        $document = json_decode((string) file_get_contents($geoJsonFile), true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($document) || !\is_array($document['features'] ?? null)) {
            throw new \RuntimeException(\sprintf('Polygonize output %s is not a FeatureCollection.', $geoJsonFile));
        }

        $batch = 0;
        foreach ($document['features'] as $feature) {
            if (!\is_array($feature) || !\is_array($feature['geometry'] ?? null)) {
                continue;
            }
            $properties = \is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $dn = $properties['dn'] ?? null;
            if (!is_numeric($dn) || (int) $dn < 1 || (int) $dn > 23) {
                continue; // 0 = no loss, 255 = nodata — not loss data
            }

            $this->em->persist(
                (new HansenLossPolygon())
                    ->setDn((int) $dn)
                    ->setGeom((string) json_encode($feature['geometry'], \JSON_THROW_ON_ERROR)),
            );
            if (0 === ++$batch % self::BATCH_SIZE) {
                $this->em->flush();
                $this->em->clear();
            }
        }
        $this->em->flush();
        $this->em->clear();
    }

    /**
     * Dissolves staging into one MultiPolygon per loss year — entirely in DQL
     * via the PostGIS bundle's functions — and replaces this area's rows as
     * ForestLossYear ENTITIES, in one transaction.
     *
     * @return array<string, mixed>
     */
    private function dissolve(IngestForestLoss $message): array
    {
        /** @var list<array{dn: int, geojson: string, m2: string|float}> $rows */
        $rows = $this->em->createQuery(\sprintf(
            'SELECT p.dn AS dn,
                    ST_AsGeoJSON(ST_Multi(ST_CollectionExtract(ST_MakeValid(
                        ST_SimplifyPreserveTopology(ST_Union(p.geom), :tolerance)), 3))) AS geojson,
                    SUM(ST_Area(Geography(p.geom))) AS m2
             FROM %s p
             GROUP BY p.dn
             ORDER BY p.dn',
            HansenLossPolygon::class,
        ))->setParameter('tolerance', $message->simplifyDegrees)->getArrayResult();

        // The load cleared the EntityManager — fetch a managed AOI reference.
        $aoi = $this->em->find(AreaOfInterest::class, $message->aoiId)
            ?? throw new \RuntimeException(\sprintf('AreaOfInterest %d disappeared mid-run.', $message->aoiId));

        $byYear = [];
        $total = 0.0;
        $this->em->wrapInTransaction(function () use ($rows, $aoi, $message, &$byYear, &$total): void {
            // Replace = native remove() of this area's previous rows (~a couple
            // dozen entities — the plain ORM way; no bulk query needed).
            foreach ($this->lossYears->findBy(['aoi' => $message->aoiId, 'source' => $message->source]) as $previous) {
                $this->em->remove($previous);
            }
            foreach ($rows as $row) {
                $year = 2000 + (int) $row['dn'];
                $areaHa = round((float) $row['m2'] / 10_000);
                $this->em->persist(
                    (new ForestLossYear())
                        ->setAoi($aoi)
                        ->setYear($year)
                        ->setGeom($row['geojson'])
                        ->setAreaHa($areaHa)
                        ->setSource($message->source),
                );
                $byYear[(string) $year] = $areaHa;
                $total += $areaHa;
            }
            $this->em->flush();
        });

        // Publish the same series into the generic per-module Dataset store: the data-driven chart
        // engine (and the dataframe/statistics tabs) read a module's data THERE, so Forest's charts
        // render like any other module's — no bespoke drawer. One row per year, plus the running total.
        ksort($byYear, \SORT_NUMERIC);
        $cumulative = 0.0;
        $seriesRows = [];
        foreach ($byYear as $year => $areaHa) {
            $cumulative += $areaHa;
            $seriesRows[] = [(int) $year, (float) $areaHa, round($cumulative)];
        }
        $this->datasets->upsert($aoi, 'forest', 'forest_loss_year')
            ->setKind(DatasetKind::Series)
            ->setColumns(['year', 'ha', 'cumulative_ha'])
            ->setRows($seriesRows)
            ->setSource($message->source);
        $this->em->flush();

        return ['years' => \count($rows), 'totalHa' => $total, 'byYearHa' => $byYear];
    }

    /**
     * @return array{float, float, float, float} [minX, minY, maxX, maxY]
     */
    private static function bbox(string $geoJson): array
    {
        $geometry = json_decode($geoJson, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($geometry) || !\is_array($geometry['coordinates'] ?? null)) {
            throw new \RuntimeException('AOI geometry has no coordinates.');
        }

        $minX = $minY = \INF;
        $maxX = $maxY = -\INF;
        $walk = static function (array $coords) use (&$walk, &$minX, &$minY, &$maxX, &$maxY): void {
            if (is_numeric($coords[0] ?? null)) {
                $x = (float) $coords[0];
                $y = is_numeric($coords[1] ?? null) ? (float) $coords[1] : 0.0;
                $minX = min($minX, $x);
                $minY = min($minY, $y);
                $maxX = max($maxX, $x);
                $maxY = max($maxY, $y);

                return;
            }
            foreach ($coords as $part) {
                if (\is_array($part)) {
                    $walk($part);
                }
            }
        };
        $walk($geometry['coordinates']);

        if (!is_finite($minX)) {
            throw new \RuntimeException('AOI geometry holds no coordinates.');
        }

        return [$minX, $minY, $maxX, $maxY];
    }
}
