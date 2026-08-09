<?php

declare(strict_types=1);

namespace App\Tests\Integration\Spatial;

use App\Spatial\Exception\BoundaryImportException;
use App\Spatial\Service\BoundaryImportService;
use App\Spatial\Service\GeoJsonNormalizerService;
use Doctrine\ORM\EntityManagerInterface;
use FundiStadi\GDALBundle\Process\GdalBinaryLocator;
use FundiStadi\GDALBundle\Process\GdalRunner;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The upload path for boundaries in ANY common GIS format: GeoJSON directly;
 * everything else through ogr2ogr (zipped shapefile here — including
 * REPROJECTION from UTM, the classic real-world case) with the polygon layer
 * auto-picked. Geometry always lands as a 4326 MultiPolygon via the ORM.
 */
final class BoundaryImportServiceTest extends KernelTestCase
{
    private GdalRunner $runner;
    private string $workdir;

    private const string SQUARE_GEOJSON = '{"type":"Feature","properties":{"name":"sq"},"geometry":{"type":"Polygon","coordinates":[[[35.2,-3.8],[35.8,-3.8],[35.8,-3.2],[35.2,-3.2],[35.2,-3.8]]]}}';

    protected function setUp(): void
    {
        $locator = new GdalBinaryLocator();
        foreach (['ogrinfo', 'ogr2ogr'] as $binary) {
            if (!$locator->has($binary)) {
                self::markTestSkipped(\sprintf('"%s" not available — brew install gdal / apt install gdal-bin', $binary));
            }
        }
        $this->runner = new GdalRunner($locator);
        $this->workdir = sys_get_temp_dir().'/boundary-test-'.bin2hex(random_bytes(4));
        mkdir($this->workdir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        array_map(unlink(...), glob($this->workdir.'/*') ?: []);
        @rmdir($this->workdir);
    }

    private function service(): BoundaryImportService
    {
        self::bootKernel();

        // Constructed directly: until the Dashboard controller consumes it, the
        // compiled container inlines the (private) service away.
        return new BoundaryImportService(
            $this->runner,
            new GeoJsonNormalizerService(),
            self::getContainer()->get(EntityManagerInterface::class),
        );
    }

    public function testImportsAGeoJsonFileDirectly(): void
    {
        $file = $this->workdir.'/boundary.geojson';
        file_put_contents($file, self::SQUARE_GEOJSON);

        $aoi = $this->service()->import($file, 'boundary.geojson', 'GeoJSON area', 'test');

        self::assertNotNull($aoi->getId());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var array{t: string, srid: int}|false $row */
        $row = $em->getConnection()->fetchAssociative(
            'SELECT GeometryType(geom) AS t, ST_SRID(geom) AS srid FROM area_of_interest WHERE id = :id',
            ['id' => $aoi->getId()],
        );
        self::assertNotFalse($row);
        self::assertSame('MULTIPOLYGON', $row['t']);
        self::assertSame(4326, (int) $row['srid']);
    }

    public function testImportsAZippedUtmShapefileWithReprojectionAndLayerAutoPick(): void
    {
        // Build a REAL shapefile in UTM zone 36S (EPSG:32736) — what a GIS office
        // typically hands over — and zip it.
        $geojson = $this->workdir.'/src.geojson';
        file_put_contents($geojson, self::SQUARE_GEOJSON);
        $this->runner->run([
            'ogr2ogr', '-f', 'ESRI Shapefile', '-t_srs', 'EPSG:32736',
            $this->workdir.'/shape', $geojson,
        ]);
        $zipPath = $this->workdir.'/boundary.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        foreach (glob($this->workdir.'/shape/*') ?: [] as $part) {
            $zip->addFile($part, basename($part));
        }
        $zip->close();

        $aoi = $this->service()->import($zipPath, 'boundary.zip', 'Shapefile area', 'test');

        // Geometry must come back in 4326 at the original lon/lat neighbourhood.
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var array{srid: int, x: float, y: float}|false $row */
        $row = $em->getConnection()->fetchAssociative(
            'SELECT ST_SRID(geom) AS srid, ST_X(ST_Centroid(geom)) AS x, ST_Y(ST_Centroid(geom)) AS y FROM area_of_interest WHERE id = :id',
            ['id' => $aoi->getId()],
        );
        self::assertNotFalse($row);
        self::assertSame(4326, (int) $row['srid']);
        self::assertEqualsWithDelta(35.5, (float) $row['x'], 0.05);
        self::assertEqualsWithDelta(-3.5, (float) $row['y'], 0.05);
    }

    public function testImportsAWdpaStyleNestedZip(): void
    {
        // WDPA's real download shape: an OUTER zip holding the shapefile in a
        // NESTED zip, next to CSVs and PDFs.
        $geojson = $this->workdir.'/src.geojson';
        file_put_contents($geojson, self::SQUARE_GEOJSON);
        $this->runner->run(['ogr2ogr', '-f', 'ESRI Shapefile', $this->workdir.'/shape', $geojson]);

        $inner = $this->workdir.'/WDPA_test_shp_0.zip';
        $zip = new \ZipArchive();
        $zip->open($inner, \ZipArchive::CREATE);
        foreach (glob($this->workdir.'/shape/*') ?: [] as $part) {
            $zip->addFile($part, basename($part));
        }
        $zip->close();

        $outer = $this->workdir.'/WDPA_test_shp.zip';
        $zip = new \ZipArchive();
        $zip->open($outer, \ZipArchive::CREATE);
        $zip->addFile($inner, 'WDPA_test_shp_0.zip');
        $zip->addFromString('WDPA_sources.csv', "id,name\n1,x\n");
        $zip->addFromString('Resources_in_English/Manual.pdf', '%PDF-fake');
        $zip->close();

        $aoi = $this->service()->import($outer, 'WDPA_test_shp.zip', 'Nested WDPA area', 'test');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var array{t: string, srid: int}|false $row */
        $row = $em->getConnection()->fetchAssociative(
            'SELECT GeometryType(geom) AS t, ST_SRID(geom) AS srid FROM area_of_interest WHERE id = :id',
            ['id' => $aoi->getId()],
        );
        self::assertNotFalse($row);
        self::assertSame('MULTIPOLYGON', $row['t']);
        self::assertSame(4326, (int) $row['srid']);
    }

    public function testAGeometrylessFileIsRejectedWithAFriendlyError(): void
    {
        $file = $this->workdir.'/attributes.csv';
        file_put_contents($file, "name,area\nNgorongoro,8271\n");

        $this->expectException(BoundaryImportException::class);
        $this->expectExceptionMessageMatches('/polygon/i');

        $this->service()->import($file, 'attributes.csv', 'CSV', 'test');
    }
}
