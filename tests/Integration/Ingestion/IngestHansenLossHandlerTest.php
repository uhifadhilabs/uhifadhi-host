<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Entity\DatasetRun;
use App\Ingestion\Message\IngestHansenLoss;
use App\Ingestion\MessageHandler\IngestHansenLossHandler;
use App\Ingestion\Repository\DatasetRunRepository;
use App\Ingestion\Repository\HansenLossPolygonRepository;
use App\Ingestion\Service\TileSourceInterface;
use App\Forest\Factory\ForestLossYearFactory;
use App\Forest\Repository\ForestLossYearRepository;
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
 * Fully DAMA-covered: every write (staging included) rides the test transaction.
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

    public function testThePipelineIsPerAreaAndLandsDissolvedLossAndProvenance(): void
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
        // ANOTHER area already has loss rows from the same dataset — ingesting
        // $aoi must not touch them.
        $other = AreaOfInterestFactory::createOne(['name' => 'Untouched neighbour']);
        ForestLossYearFactory::createOne(['aoi' => $other, 'year' => 2007, 'source' => 'hansen_test']);

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
            $container->get(ForestLossYearRepository::class),
            $container->get(HansenLossPolygonRepository::class),
        );
        $handler(new IngestHansenLoss(aoiId: (int) $aoi->getId(), version: 'TEST', source: 'hansen_test'));

        // One dissolved MultiPolygon for 2013, owned by $aoi.
        /** @var array{year: int, area_ha: float, t: string, aoi_id: int}|false $row */
        $row = $em->getConnection()->fetchAssociative(
            "SELECT year, area_ha, GeometryType(geom) AS t, aoi_id FROM forest_loss_year WHERE source = 'hansen_test' AND aoi_id = :aoi",
            ['aoi' => $aoi->getId()],
        );
        self::assertNotFalse($row, 'expected exactly one forest_loss_year row for the ingested area');
        self::assertSame(2013, (int) $row['year']);
        self::assertSame('MULTIPOLYGON', $row['t']);
        self::assertGreaterThan(300_000, (float) $row['area_ha']);
        self::assertLessThan(600_000, (float) $row['area_ha']);

        // The neighbour's row survived the replace.
        $neighbour = $em->getConnection()->fetchOne(
            "SELECT count(*) FROM forest_loss_year WHERE aoi_id = :aoi AND source = 'hansen_test'",
            ['aoi' => $other->getId()],
        );
        self::assertSame(1, (int) $neighbour, 'ingesting one area must not delete another area\'s rows');

        // Provenance: a succeeded run linked to the area, carrying the report.
        $run = $container->get(DatasetRunRepository::class)
            ->findOneBy(['dataset' => 'hansen_gfc_lossyear'], ['id' => 'DESC']);
        self::assertNotNull($run);
        self::assertSame(DatasetRun::STATUS_SUCCEEDED, $run->getStatus());
        self::assertSame($aoi->getId(), $run->getAoi()?->getId());
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
            $container->get(ForestLossYearRepository::class),
            $container->get(HansenLossPolygonRepository::class),
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
