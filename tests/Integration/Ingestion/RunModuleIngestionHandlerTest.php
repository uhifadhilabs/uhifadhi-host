<?php

declare(strict_types=1);

namespace Uhifadhi\Tests\Integration\Ingestion;

use Uhifadhi\Ingestion\Entity\DatasetRun;
use Uhifadhi\Ingestion\Enum\DatasetKind;
use Uhifadhi\Ingestion\Message\RunModuleIngestion;
use Uhifadhi\Ingestion\MessageHandler\RunModuleIngestionHandler;
use Uhifadhi\Ingestion\Repository\DatasetRepository;
use Uhifadhi\Ingestion\Repository\DatasetRunRepository;
use Uhifadhi\Ingestion\Service\SpatialFeatureIngestor;
use Uhifadhi\Spatial\Factory\AreaOfInterestFactory;
use Uhifadhi\Spatial\Repository\AreaOfInterestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * The handler is the write path of the engine contract: it POSTs an area's geometry to the geoprocessing
 * engine, upserts each returned dataset into the generic store (keyed by module + key), and records a
 * DatasetRun for provenance. The engine is mocked here — a failed call marks the run failed and
 * re-throws so Messenger retries, and no partial data is stored.
 */
final class RunModuleIngestionHandlerTest extends KernelTestCase
{
    use Factories;

    public function testStoresEngineDatasetsAndRecordsASucceededRun(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();

        $captured = null;
        $engine = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse((string) json_encode([
                'run' => ['module' => 'landcover', 'status' => 'succeeded', 'source' => 'ESA WorldCover 2021 v200'],
                'datasets' => [
                    ['key' => 'landcover_area', 'kind' => 'series', 'columns' => ['class', 'area_km2', 'pct'], 'rows' => [['Grassland', 5123.4, 61.8]]],
                    ['key' => 'landcover_map', 'kind' => 'vector', 'path' => '/data/out/landcover.geojson'],
                ],
            ]), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
        }, 'http://engine.test');

        ($this->handler($engine, 'secret-token'))(new RunModuleIngestion((int) $area->getId(), 'landcover', ['res_m' => 30]));

        // Tabular dataset stored inline.
        $repo = self::getContainer()->get(DatasetRepository::class);
        \assert($repo instanceof DatasetRepository);
        $areaDs = $repo->findOneFor($area, 'landcover', 'landcover_area');
        self::assertNotNull($areaDs);
        self::assertSame(DatasetKind::Series, $areaDs->getKind());
        self::assertSame(['class', 'area_km2', 'pct'], $areaDs->getColumns());
        self::assertSame([['Grassland', 5123.4, 61.8]], $areaDs->getRows());
        self::assertSame('ESA WorldCover 2021 v200', $areaDs->getSource());

        // Spatial dataset stored as a file path.
        $mapDs = $repo->findOneFor($area, 'landcover', 'landcover_map');
        self::assertNotNull($mapDs);
        self::assertSame(DatasetKind::Vector, $mapDs->getKind());
        self::assertSame('/data/out/landcover.geojson', $mapDs->getPath());

        // A succeeded run recorded and linked to the data it produced.
        $runs = $this->runs();
        self::assertCount(1, $runs);
        self::assertSame(DatasetRun::STATUS_SUCCEEDED, $runs[0]->getStatus());
        self::assertNotNull($runs[0]->getFinishedAt());
        self::assertSame($runs[0]->getId(), $areaDs->getRun()?->getId());

        // The engine was called correctly: POST /run/<module>, token header, AOI as a GeoJSON Feature.
        self::assertNotNull($captured);
        self::assertSame('POST', $captured['method']);
        self::assertSame('http://engine.test/run/landcover', $captured['url']);
        self::assertContains('X-Engine-Token: secret-token', $captured['options']['headers']);
        $body = json_decode((string) $captured['options']['body'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('Feature', $body['aoi']['type']);
        self::assertSame('MultiPolygon', $body['aoi']['geometry']['type']);
        self::assertSame(['res_m' => 30], $body['params']);
    }

    public function testFailedEngineCallMarksRunFailedRethrowsAndStoresNoData(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $engine = new MockHttpClient(new MockResponse('engine boom', ['http_code' => 500]), 'http://engine.test');

        try {
            ($this->handler($engine, 'secret-token'))(new RunModuleIngestion((int) $area->getId(), 'landcover'));
            self::fail('the engine failure must propagate so Messenger can retry');
        } catch (\Throwable) {
            // expected
        }

        $runs = $this->runs();
        self::assertCount(1, $runs);
        self::assertSame(DatasetRun::STATUS_FAILED, $runs[0]->getStatus());
        self::assertNotNull($runs[0]->getError());
        self::assertNotNull($runs[0]->getFinishedAt());

        $repo = self::getContainer()->get(DatasetRepository::class);
        \assert($repo instanceof DatasetRepository);
        self::assertCount(0, $repo->findAll(), 'a failed run must not leave partial datasets');
    }

    public function testARerunReplacesTheModulesDataDroppingStaleKeys(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $repo = self::getContainer()->get(DatasetRepository::class);
        \assert($repo instanceof DatasetRepository);

        // A prior run left two split tables on the module.
        $repo->upsert($area, 'landcover', 'landcover_area')->setKind(DatasetKind::Series)->setSource('old');
        $repo->upsert($area, 'landcover', 'fragmentation_class')->setKind(DatasetKind::Table)->setSource('old');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        // The new run produces a single merged table (+ the map) instead.
        $engine = new MockHttpClient(new MockResponse((string) json_encode([
            'run' => ['module' => 'landcover', 'status' => 'succeeded', 'source' => 'ESA WorldCover 2021 v200'],
            'datasets' => [
                ['key' => 'landcover_class', 'kind' => 'table', 'columns' => ['class', 'area_km2'], 'rows' => [['Grassland', 2589.78]]],
                ['key' => 'landcover_map', 'kind' => 'vector', 'path' => '/data/out/landcover.geojson'],
            ],
        ]), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]), 'http://engine.test');

        ($this->handler($engine, 'secret-token'))(new RunModuleIngestion((int) $area->getId(), 'landcover'));

        $keys = array_map(static fn ($d) => $d->getKey(), $repo->forModule($area, 'landcover'));
        self::assertSame(['landcover_class', 'landcover_map'], $keys, 'the stale split tables are gone');
    }

    public function testARasterDatasetIsStoredInlineWithItsOverlayMeta(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        $engine = new MockHttpClient(new MockResponse((string) json_encode([
            'run' => ['module' => 'vegetation', 'status' => 'succeeded', 'source' => 'MODIS MOD13Q1'],
            'datasets' => [
                ['key' => 'ndvi_peak', 'kind' => 'raster', 'format' => 'png',
                 'bounds' => [[-3.61, 34.88], [-2.50, 35.97]], 'data' => $png],
            ],
        ]), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]), 'http://engine.test');

        ($this->handler($engine, 'secret-token'))(new RunModuleIngestion((int) $area->getId(), 'vegetation'));

        $repo = self::getContainer()->get(DatasetRepository::class);
        \assert($repo instanceof DatasetRepository);
        $raster = $repo->findOneFor($area, 'vegetation', 'ndvi_peak');
        self::assertNotNull($raster);
        self::assertSame(DatasetKind::Raster, $raster->getKind());
        self::assertSame($png, $raster->getPayload());
        self::assertSame('png', $raster->getMeta()['format'] ?? null);
        self::assertSame([[-3.61, 34.88], [-2.50, 35.97]], $raster->getMeta()['bounds'] ?? null);
    }

    public function testADatasetAfterAVectorLayerSurvivesTheSpatialIngestsEntityManagerClear(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        // The vector layer's staged ingest clears the EntityManager mid-store; the raster AFTER it
        // must still land, linked to managed copies of the area and the run (roads regression).
        $engine = new MockHttpClient(new MockResponse((string) json_encode([
            'run' => ['module' => 'roads', 'status' => 'succeeded', 'source' => 'OpenStreetMap · Overpass'],
            'datasets' => [
                ['key' => 'roads_map', 'kind' => 'vector', 'attribute' => 'label', 'simplify' => 0.00005,
                 'geojson' => ['type' => 'FeatureCollection', 'features' => [[
                     'type' => 'Feature', 'properties' => ['label' => 'trunk'],
                     'geometry' => ['type' => 'Polygon', 'coordinates' => [[[35.0, -3.1], [35.1, -3.1], [35.1, -3.0], [35.0, -3.1]]]],
                 ]]]],
                ['key' => 'remoteness', 'kind' => 'raster', 'format' => 'png',
                 'bounds' => [[-3.61, 34.88], [-2.50, 35.97]], 'data' => $png],
            ],
        ]), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]), 'http://engine.test');

        ($this->handler($engine, 'secret-token'))(new RunModuleIngestion((int) $area->getId(), 'roads'));

        $repo = self::getContainer()->get(DatasetRepository::class);
        \assert($repo instanceof DatasetRepository);
        $raster = $repo->findOneFor($area, 'roads', 'remoteness');
        self::assertNotNull($raster, 'the raster after the vector layer must still be stored');
        self::assertSame($png, $raster->getPayload());
        self::assertNotNull($raster->getRun()?->getId());
        self::assertSame(DatasetRun::STATUS_SUCCEEDED, $this->runs()[0]->getStatus());
    }

    private function handler(HttpClientInterface $engine, string $token): RunModuleIngestionHandler
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $areas = $c->get(AreaOfInterestRepository::class);
        $datasets = $c->get(DatasetRepository::class);
        $spatial = $c->get(SpatialFeatureIngestor::class);
        \assert($em instanceof EntityManagerInterface);
        \assert($areas instanceof AreaOfInterestRepository);
        \assert($datasets instanceof DatasetRepository);
        \assert($spatial instanceof SpatialFeatureIngestor);

        return new RunModuleIngestionHandler($em, $engine, $areas, $datasets, $spatial, $token);
    }

    /** @return list<DatasetRun> */
    private function runs(): array
    {
        $repo = self::getContainer()->get(DatasetRunRepository::class);
        \assert($repo instanceof DatasetRunRepository);

        return array_values($repo->findAll());
    }
}
