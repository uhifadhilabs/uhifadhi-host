<?php

declare(strict_types=1);

namespace App\Tests\Integration\Statistics;

use App\Ingestion\Enum\DatasetKind;
use App\Ingestion\Factory\DatasetFactory;
use App\Ingestion\Repository\DatasetRepository;
use App\Spatial\Factory\AreaOfInterestFactory;
use App\Statistics\Service\SynthesisService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The Q6 synthesis derives entirely from the OTHER modules' stored datasets — no engine, no
 * source of its own. refresh() materializes `synthesis` + `provenance` as ordinary dataframes
 * in the generic store, and provenance self-documents from the registered definitions' captions.
 */
final class SynthesisServiceTest extends KernelTestCase
{
    use Factories;

    public function testIndicatorsDeriveFromTheStoredModuleDatasets(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        DatasetFactory::createOne([
            'area' => $area, 'moduleSlug' => 'settlement', 'key' => 'builtup_epoch',
            'kind' => DatasetKind::Series,
            'columns' => ['year', 'built_km2_in', 'built_km2_ring', 'pct_of_area', 'growth_x'],
            'rows' => [[1975, 1.18, 2.48, 0.014, 1.0], [2020, 4.23, 10.37, 0.051, 3.58]],
            'source' => 'GHSL GHS-BUILT-S R2023A · 1 km',
        ]);
        DatasetFactory::createOne([
            'area' => $area, 'moduleSlug' => 'roads', 'key' => 'roads_stats',
            'kind' => DatasetKind::Table, 'columns' => ['metric', 'value'],
            'rows' => [['total_km', 2345.6], ['density_km_per_km2', 0.2836], ['remote_pct_gt2km', 51.0]],
            'source' => 'OpenStreetMap · Overpass',
        ]);

        $service = self::getContainer()->get(SynthesisService::class);
        \assert($service instanceof SynthesisService);
        $rows = $service->indicators($area);

        $byIndicator = array_column($rows, null, 1);
        self::assertSame(4.23, $byIndicator['Built-up area 2020'][2]);
        self::assertSame(1.18, $byIndicator['Built-up area 1975'][2]);
        self::assertSame('GHSL GHS-BUILT-S R2023A · 1 km', $byIndicator['Built-up area 2020'][4]);
        self::assertSame(2345.6, $byIndicator['Mapped road network'][2]);
        self::assertSame(51.0, $byIndicator['More than 2 km from any road'][2]);
        // Missing modules simply contribute nothing; the boundary cross-check is always present.
        self::assertArrayNotHasKey('Peak-season NDVI (spatial median)', $byIndicator);
        self::assertSame('Cross-check', end($rows)[0]);
    }

    public function testRefreshMaterializesSynthesisAndProvenanceInTheGenericStore(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();

        $service = self::getContainer()->get(SynthesisService::class);
        \assert($service instanceof SynthesisService);
        $service->refresh($area);

        $repo = self::getContainer()->get(DatasetRepository::class);
        \assert($repo instanceof DatasetRepository);
        $synthesis = $repo->findOneFor($area, 'statistics', 'synthesis');
        self::assertNotNull($synthesis);
        self::assertSame(['module', 'indicator', 'value', 'unit', 'source'], $synthesis->getColumns());

        // Provenance self-documents from every registered definition's Method caption.
        $provenance = $repo->findOneFor($area, 'statistics', 'provenance');
        self::assertNotNull($provenance);
        $modules = array_column($provenance->getRows() ?? [], 0);
        self::assertContains('forest', $modules);
        self::assertContains('vegetation', $modules);
        self::assertContains('roads', $modules);
        $byModule = array_column($provenance->getRows() ?? [], null, 0);
        self::assertSame('MODIS MOD13Q1 v6.1', $byModule['vegetation'][1]);
    }

    public function testChartReadyFramesAreProjectedFromTheSourceModules(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        DatasetFactory::createOne([
            'area' => $area, 'moduleSlug' => 'settlement', 'key' => 'builtup_epoch',
            'kind' => DatasetKind::Series,
            'columns' => ['year', 'built_km2_in', 'built_km2_ring', 'pct_of_area', 'growth_x'],
            'rows' => [[1975, 1.18, 2.48, 0.014, 1.0], [2020, 4.23, 10.37, 0.051, 3.58]],
            'source' => 'GHSL GHS-BUILT-S R2023A · 1 km',
        ]);

        $service = self::getContainer()->get(SynthesisService::class);
        \assert($service instanceof SynthesisService);
        $service->refresh($area);

        $repo = self::getContainer()->get(DatasetRepository::class);
        \assert($repo instanceof DatasetRepository);
        $trend = $repo->findOneFor($area, 'statistics', 'builtup_trend');
        self::assertNotNull($trend, 'the settlement source materializes its chart frame');
        self::assertSame(['year', 'built_km2'], $trend->getColumns());
        self::assertSame([[1975, 1.18], [2020, 4.23]], $trend->getRows());
        // A source that does not exist contributes no frame.
        self::assertNull($repo->findOneFor($area, 'statistics', 'greenness_curve'));
    }
}
