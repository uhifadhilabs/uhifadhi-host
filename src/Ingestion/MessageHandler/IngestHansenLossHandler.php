<?php

declare(strict_types=1);

namespace App\Ingestion\MessageHandler;

use App\Ingestion\Entity\DatasetRun;
use App\Ingestion\Message\IngestHansenLoss;
use App\Ingestion\Service\TileSourceInterface;
use App\Spatial\Repository\AreaOfInterestRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use FundiStadi\GDALBundle\Process\GdalRunner;
use FundiStadi\GDALBundle\Tool\Gdalwarp;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The Hansen ETL, entirely on compiled tools + PostGIS SQL:
 *
 *   gdalwarp (clip granule to AOI, nodata 255)     — gdal-bundle
 *   gdal raster polygonize (pixels → polygons)     — GDAL 3.11+ C++ subcommand
 *   ogr2ogr → ingest_hansen_raw staging table      — OGR metadata disabled
 *   SQL: dissolve per year → forest_loss_year      — transactional replace
 *
 * Every run writes a DatasetRun provenance row (params, per-year report, error).
 * The nodata=255 and staging-table hygiene here encode two data bugs hit during
 * the original manual run: -dstnodata 0 silently turns no-loss pixels into
 * "year 2001", and stale intermediate outputs mix runs.
 */
#[AsMessageHandler]
final class IngestHansenLossHandler
{
    private const string STAGING_TABLE = 'ingest_hansen_raw';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GdalRunner $gdal,
        private readonly TileSourceInterface $tiles,
        private readonly AreaOfInterestRepository $areas,
    ) {
    }

    public function __invoke(IngestHansenLoss $message): void
    {
        $run = (new DatasetRun())
            ->setDataset('hansen_gfc_lossyear')
            ->setParams([
                'aoiId' => $message->aoiId,
                'version' => $message->version,
                'source' => $message->source,
                'simplifyDegrees' => $message->simplifyDegrees,
            ])
            ->setStartedAt(new \DateTimeImmutable());
        $this->em->persist($run);
        $this->em->flush();

        $workdir = sys_get_temp_dir().'/ingest-hansen-'.bin2hex(random_bytes(4));
        mkdir($workdir);

        try {
            $report = $this->pipeline($message, $workdir);
            $run->setStatus(DatasetRun::STATUS_SUCCEEDED)->setReport($report);
        } catch (\Throwable $e) {
            $run->setStatus(DatasetRun::STATUS_FAILED)->setError($e->getMessage());

            throw $e;
        } finally {
            $run->setFinishedAt(new \DateTimeImmutable());
            $this->em->flush();
            array_map(unlink(...), glob($workdir.'/*') ?: []);
            @rmdir($workdir);
        }
    }

    /**
     * @return array<string, mixed> the per-year report stored on the DatasetRun
     */
    private function pipeline(IngestHansenLoss $message, string $workdir): array
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

        $connection = $this->em->getConnection();
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

            // Pixels → polygons (compiled C++ subcommand, replaces gdal_polygonize.py).
            $vectors = \sprintf('%s/loss_%d.gpkg', $workdir, $i);
            $this->gdal->run([
                'gdal', 'raster', 'polygonize', '--quiet', '--overwrite',
                '--band', '1', '--attribute-name', 'dn',
                '-i', $clip, '-o', $vectors,
            ]);

            // Load into the staging table (Doctrine-invisible via schema_filter).
            // OGR metadata bookkeeping is disabled so ogr_system_tables never appears.
            $this->gdal->run([
                'ogr2ogr', '-f', 'PostgreSQL', $this->pgConnectionString($connection), $vectors,
                '-nln', self::STAGING_TABLE, '-lco', 'GEOMETRY_NAME=geom',
                '--config', 'OGR_PG_ENABLE_METADATA', 'NO',
                0 === $i ? '-overwrite' : '-append',
            ]);
        }

        return $this->dissolve($connection, $message);
    }

    /**
     * Dissolve the staging polygons into one MultiPolygon per loss year and
     * replace this source's rows in forest_loss_year, in one transaction.
     *
     * @return array<string, mixed>
     */
    private function dissolve(Connection $connection, IngestHansenLoss $message): array
    {
        $connection->beginTransaction();
        try {
            $connection->executeStatement(
                'DELETE FROM forest_loss_year WHERE source = :source',
                ['source' => $message->source],
            );
            $connection->executeStatement(
                'INSERT INTO forest_loss_year (year, geom, area_ha, source)
                 SELECT dn + 2000,
                        ST_Multi(ST_CollectionExtract(ST_MakeValid(
                            ST_SimplifyPreserveTopology(ST_Union(geom), :simplify)), 3)),
                        round((SUM(ST_Area(geom::geography)) / 10000)::numeric)::float,
                        :source
                 FROM '.self::STAGING_TABLE.'
                 WHERE dn BETWEEN 1 AND 23
                 GROUP BY dn',
                ['simplify' => $message->simplifyDegrees, 'source' => $message->source],
            );
            $connection->executeStatement('DROP TABLE IF EXISTS '.self::STAGING_TABLE);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }

        /** @var list<array{year: int, area_ha: float}> $rows */
        $rows = $connection->fetchAllAssociative(
            'SELECT year, area_ha FROM forest_loss_year WHERE source = :source ORDER BY year',
            ['source' => $message->source],
        );
        $byYear = [];
        $total = 0.0;
        foreach ($rows as $row) {
            $byYear[(string) $row['year']] = (float) $row['area_ha'];
            $total += (float) $row['area_ha'];
        }

        return ['years' => \count($rows), 'totalHa' => $total, 'byYearHa' => $byYear];
    }

    /**
     * OGR PG connection string from the app's own Doctrine connection — one
     * database, one source of truth.
     */
    private function pgConnectionString(Connection $connection): string
    {
        $params = $connection->getParams();
        $parts = [
            'host='.(\is_string($params['host'] ?? null) ? $params['host'] : '127.0.0.1'),
            'port='.(is_numeric($params['port'] ?? null) ? (string) $params['port'] : '5432'),
            'dbname='.(\is_string($params['dbname'] ?? null) ? $params['dbname'] : ''),
            'user='.(\is_string($params['user'] ?? null) ? $params['user'] : ''),
        ];
        if (\is_string($params['password'] ?? null) && '' !== $params['password']) {
            $parts[] = 'password='.$params['password'];
        }

        return 'PG:'.implode(' ', $parts);
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
