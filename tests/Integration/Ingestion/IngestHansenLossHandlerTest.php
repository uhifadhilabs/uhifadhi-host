<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Entity\DatasetRun;
use App\Ingestion\Message\IngestHansenLoss;
use App\Ingestion\MessageHandler\IngestHansenLossHandler;
use App\Ingestion\Repository\DatasetRunRepository;
use App\Ingestion\Service\TileSourceInterface;
use App\Spatial\Factory\AreaOfInterestFactory;
use App\Spatial\Repository\AreaOfInterestRepository;
use Doctrine\ORM\EntityManagerInterface;
use FundiStadi\GDALBundle\Process\GdalBinaryLocator;
use FundiStadi\GDALBundle\Process\GdalRunner;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The whole Hansen ETL against REAL GDAL + PostGIS, network-free: the tile
 * source is stubbed with a locally generated raster (every pixel = 13, i.e.
 * loss year 2013), so the pipeline — clip → polygonize → ogr2ogr staging →
 * dissolve — runs end to end and lands one dissolved 2013 MultiPolygon in
 * forest_loss_year plus a succeeded DatasetRun.
 *
 * Note: ogr2ogr writes the staging table over its OWN connection (committed,
 * outside DAMA's transaction); the handler drops it inside the rolled-back
 * transaction, so an empty staging table may linger in dnca_test — harmless,
 * the next run's -overwrite replaces it.
 */
final class IngestHansenLossHandlerTest extends KernelTestCase
{
    use Factories;

    private GdalRunner $runner;
    private string $workdir;

    protected function setUp(): void
    {
        $locator = new GdalBinaryLocator();
        foreach (['gdal', 'gdalwarp', 'gdal_create', 'ogr2ogr'] as $binary) {
            if (!$locator->has($binary)) {
                self::markTestSkipped(\sprintf('"%s" not available — brew install gdal / apt install gdal-bin', $binary));
            }
        }
        $this->runner = new GdalRunner($locator);
        $this->workdir = sys_get_temp_dir().'/ingest-test-'.bin2hex(random_bytes(4));
        mkdir($this->workdir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        array_map(unlink(...), glob($this->workdir.'/*') ?: []);
        @rmdir($this->workdir);
    }

    public function testThePipelineLandsDissolvedLossAndProvenance(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        // A fake Hansen granule: 40×40 Byte raster over lon 35…36 / lat −4…−3,
        // every pixel value 13 (= loss year 2013).
        $tile = $this->workdir.'/granule.tif';
        $this->runner->run([
            'gdal_create', '-of', 'GTiff', '-outsize', '40', '40', '-bands', '1',
            '-burn', '13', '-ot', 'Byte', '-a_srs', 'EPSG:4326',
            '-a_ullr', '35', '-3', '36', '-4', $tile,
        ]);

        // AOI square strictly inside the raster (0.6°×0.6° ≈ 440k ha).
        $aoi = AreaOfInterestFactory::createOne([
            'name' => 'Pipeline test area',
            'geom' => (string) json_encode([
                'type' => 'MultiPolygon',
                'coordinates' => [[[[35.2, -3.8], [35.8, -3.8], [35.8, -3.2], [35.2, -3.2], [35.2, -3.8]]]],
            ]),
        ]);

        $stub = new class($tile) implements TileSourceInterface {
            public function __construct(private readonly string $tile)
            {
            }

            public function sources(float $minX, float $minY, float $maxX, float $maxY, string $version): array
            {
                return [$this->tile];
            }
        };

        $handler = new IngestHansenLossHandler(
            $em,
            $this->runner,
            $stub,
            $container->get(AreaOfInterestRepository::class),
        );
        $handler(new IngestHansenLoss(aoiId: (int) $aoi->getId(), version: 'TEST', source: 'hansen_test'));

        // One dissolved MultiPolygon for 2013, geodesic area in the right range.
        /** @var array{year: int, area_ha: float, t: string}|false $row */
        $row = $em->getConnection()->fetchAssociative(
            "SELECT year, area_ha, GeometryType(geom) AS t FROM forest_loss_year WHERE source = 'hansen_test'",
        );
        self::assertNotFalse($row, 'expected exactly one forest_loss_year row');
        self::assertSame(2013, (int) $row['year']);
        self::assertSame('MULTIPOLYGON', $row['t']);
        self::assertGreaterThan(300_000, (float) $row['area_ha']);
        self::assertLessThan(600_000, (float) $row['area_ha']);

        // Provenance: a succeeded run carrying the per-year report.
        $run = $container->get(DatasetRunRepository::class)
            ->findOneBy(['dataset' => 'hansen_gfc_lossyear'], ['id' => 'DESC']);
        self::assertNotNull($run);
        self::assertSame(DatasetRun::STATUS_SUCCEEDED, $run->getStatus());
        self::assertNotNull($run->getFinishedAt());
        $report = $run->getReport();
        self::assertNotNull($report);
        self::assertSame(1, $report['years']);
        self::assertSame((float) $row['area_ha'], $report['totalHa']);
    }

    public function testAMissingAoiFailsAndRecordsTheError(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $stub = new class implements TileSourceInterface {
            public function sources(float $minX, float $minY, float $maxX, float $maxY, string $version): array
            {
                return [];
            }
        };
        $handler = new IngestHansenLossHandler(
            $em,
            $this->runner,
            $stub,
            $container->get(AreaOfInterestRepository::class),
        );

        try {
            $handler(new IngestHansenLoss(aoiId: 999999, source: 'hansen_test'));
            self::fail('expected the handler to throw for a missing AOI');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('not found', $e->getMessage());
        }

        $run = $container->get(DatasetRunRepository::class)
            ->findOneBy(['dataset' => 'hansen_gfc_lossyear'], ['id' => 'DESC']);
        self::assertNotNull($run);
        self::assertSame(DatasetRun::STATUS_FAILED, $run->getStatus());
        self::assertStringContainsString('not found', (string) $run->getError());
    }
}
