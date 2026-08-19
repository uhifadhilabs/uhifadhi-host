<?php

declare(strict_types=1);

namespace Uhifadhi\Tests\Functional\Dashboard;

use Uhifadhi\Ingestion\Enum\DatasetKind;
use Uhifadhi\Ingestion\Factory\DatasetFactory;
use Uhifadhi\Spatial\Factory\AreaOfInterestFactory;
use Uhifadhi\Tests\Functional\AuthenticatedWebTestCase;

/**
 * The data-backed module tabs read the module's stored Dataset: Dataframe renders the R-style viewer
 * over the real rows, Explore tabulates describe() over the numeric columns, and Method surfaces the
 * stored analysis caption (which renders whether or not the dataset exists).
 */
final class ModuleDataTabsTest extends AuthenticatedWebTestCase
{
    private function seedLandcover(object $area): void
    {
        DatasetFactory::createOne([
            'area' => $area,
            'moduleSlug' => 'landcover',
            'key' => 'landcover_class',
            'kind' => DatasetKind::Table,
            'columns' => ['class', 'area_km2', 'pct', 'n_patches'],
            'rows' => [
                ['Grassland', 2589.78, 77.16, 142],
                ['Tree cover', 199.15, 5.93, 210],
            ],
            'source' => 'ESA WorldCover 2021 v200',
        ]);
    }

    public function testTheDataframeTabRendersTheViewerOverTheRealRows(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Data area']);
        $this->seedLandcover($area);

        $client->request('GET', '/areas/'.$area->getUuidString().'/landcover/dataframe');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.rdf[data-controller~="dataframe"]');
        // Typed column header + a real cell value.
        self::assertSelectorTextContains('.rdf th[data-col="1"] .ty', 'dbl');
        self::assertSelectorTextContains('.rdf tbody', 'Grassland');
        // The footer reports the dataframe shape: 2 rows × 4 columns.
        self::assertSelectorTextContains('.rdf-foot', 'dataframe:');
        self::assertSelectorTextContains('.rdf-foot', '× 4');
    }

    public function testTheExploreTabTabulatesDescribeOverNumericColumns(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Explore area']);
        $this->seedLandcover($area);
        // A dissolved layer feature → the legend derives from the layer's labels + the palette,
        // with the class-shaped table contributing the km² value.
        $em = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        \assert($em instanceof \Doctrine\ORM\EntityManagerInterface);
        $em->persist((new \Uhifadhi\Ingestion\Entity\ModuleFeature())
            ->setAoi($area)
            ->setModuleSlug('landcover')
            ->setDatasetKey('landcover_map')
            ->setLabel('Grassland')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[35.0,-3.1],[35.1,-3.1],[35.1,-3.0],[35.0,-3.1]]]]}'));
        $em->flush();

        $client->request('GET', '/areas/'.$area->getUuidString().'/landcover/explore');

        self::assertResponseIsSuccessful();
        // The legend: the layer's Grassland label, its palette colour, and the table's area.
        self::assertSelectorTextContains('body', 'Grassland');
        self::assertSelectorExists('[style*="#f5e07a"]');
        self::assertSelectorTextContains('body', '2,590 km²');
        self::assertSelectorTextContains('.dtable', 'area_km2');
        // describe(): the class column is non-numeric, so it is not a describe row.
        self::assertSelectorTextNotContains('.dtable tbody', 'Grassland');
        // The distribution renders as bars.
        self::assertSelectorExists('.c svg rect');
    }

    public function testTheMethodTabSurfacesTheStoredCaption(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Method area']);

        // No dataset needed — Method is documentation.
        $client->request('GET', '/areas/'.$area->getUuidString().'/landcover/method');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Answers');
        self::assertSelectorTextContains('body', 'Grassland dominates');
        self::assertSelectorTextContains('body', 'ESA WorldCover 2021 v200');
    }

    public function testMultipleDatasetsGetSwitcherChipsAndTheQuerySelectsOne(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Multi area']);
        $this->seedLandcover($area);
        DatasetFactory::createOne([
            'area' => $area, 'moduleSlug' => 'landcover', 'key' => 'landcover_transitions',
            'kind' => DatasetKind::Table, 'columns' => ['from_class', 'to_class', 'km2'],
            'rows' => [['Grassland', 'Cropland', 4.2]],
        ]);
        $base = '/areas/'.$area->getUuidString().'/landcover/dataframe';

        // Default: the first dataset, with a chip per dataset (active one jade).
        $client->request('GET', $base);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.rdf-bar .tab', 'landcover_class');
        self::assertSelectorTextContains('.rdf-sel .chip.acc', 'landcover_class');
        self::assertSelectorTextContains('.rdf-sel .chip.idle', 'landcover_transitions');

        // ?dataset switches the whole viewer to the other dataframe.
        $client->request('GET', $base.'?dataset=landcover_transitions');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.rdf-bar .tab', 'landcover_transitions');
        self::assertSelectorTextContains('.rdf tbody', 'Cropland');
        self::assertSelectorTextContains('.rdf-foot', '× 3');
    }

    public function testARasterDatasetIsServedAsPngForTheImageOverlay(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Raster area']);
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        DatasetFactory::createOne([
            'area' => $area, 'moduleSlug' => 'vegetation', 'key' => 'ndvi_peak',
            'kind' => DatasetKind::Raster, 'columns' => null, 'rows' => null,
            'payload' => $png, 'meta' => ['format' => 'png', 'bounds' => [[-3.61, 34.88], [-2.50, 35.97]]],
        ]);

        $client->request('GET', '/api/areas/'.$area->getUuidString().'/vegetation/ndvi_peak.png');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertSame(base64_decode($png, true), $client->getResponse()->getContent());
    }

    public function testARasterOnlyModuleStillGetsTheExploreMap(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Raster-only area']);
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        DatasetFactory::createOne([
            'area' => $area, 'moduleSlug' => 'wildlife', 'key' => 'elephant_suitability',
            'kind' => DatasetKind::Raster, 'columns' => null, 'rows' => null,
            'payload' => $png, 'meta' => [
                'format' => 'png', 'bounds' => [[-3.61, 34.88], [-2.50, 35.97]],
                'legend' => ['label' => 'Habitat suitability', 'min' => 0, 'max' => 1,
                    'ramp' => [[0, '#440154'], [1, '#fde725']]],
            ],
        ]);

        $client->request('GET', '/areas/'.$area->getUuidString().'/wildlife/explore');

        self::assertResponseIsSuccessful();
        // No vector layer exists, but the raster overlay alone must bring up the Leaflet map.
        self::assertSelectorExists('[data-controller~="map"][data-map-raster-url-value]');
        // The surface's own legend renders as a gradient with its label and range.
        self::assertSelectorTextContains('body', 'Habitat suitability');
        self::assertSelectorExists('[style*="linear-gradient"][style*="#440154"]');
    }

    public function testATabularDatasetExportsAsCsv(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'CSV area']);
        $this->seedLandcover($area);

        $client->request('GET', '/api/areas/'.$area->getUuidString().'/landcover/landcover_class.csv');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('landcover_class.csv', (string) $client->getResponse()->headers->get('Content-Disposition'));
        $body = (string) $client->getInternalResponse()->getContent();
        self::assertStringStartsWith("class,area_km2,pct,n_patches\n", $body);
        self::assertStringContainsString('Grassland,2589.78,77.16,142', $body);
    }

    public function testAModuleWithoutADatasetKeepsThePendingShell(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Empty area']);

        $client->request('GET', '/areas/'.$area->getUuidString().'/landcover/dataframe');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.rdf');
        self::assertSelectorTextContains('body', 'awaiting data');
    }
}
