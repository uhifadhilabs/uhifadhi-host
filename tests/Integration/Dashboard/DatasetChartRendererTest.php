<?php

declare(strict_types=1);

namespace Uhifadhi\Tests\Integration\Dashboard;

use Uhifadhi\Composition\Enum\VizType;
use Uhifadhi\Composition\Factory\AreaModuleFactory;
use Uhifadhi\Composition\Factory\ModuleFactory;
use Uhifadhi\Composition\Factory\VisualizationFactory;
use Uhifadhi\Dashboard\Chart\ChartSvgService;
use Uhifadhi\Dashboard\Module\DatasetChartRenderer;
use Uhifadhi\Ingestion\Enum\DatasetKind;
use Uhifadhi\Ingestion\Factory\DatasetFactory;
use Uhifadhi\Ingestion\Repository\DatasetRepository;
use Uhifadhi\Spatial\Factory\AreaOfInterestFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The read half of the loop: a bound visualization resolves its module's dataset (by key), maps its
 * xAxis/yAxis column names to that dataset's columns, and renders SVG in the app's chart dialect. It
 * draws nothing (null → the card shows a scaffold) when the viz is unbound, the dataset is absent, or
 * a bound column doesn't exist — so a mis-wired viz degrades gracefully rather than erroring.
 */
final class DatasetChartRendererTest extends KernelTestCase
{
    use Factories;

    private function renderer(): DatasetChartRenderer
    {
        $datasets = self::getContainer()->get(DatasetRepository::class);
        \assert($datasets instanceof DatasetRepository);

        return new DatasetChartRenderer($datasets, new ChartSvgService());
    }

    public function testRendersABoundVisualizationFromItsModuleDataset(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $module = ModuleFactory::createOne(['slug' => 'landcover', 'name' => 'Land cover']);
        $areaModule = AreaModuleFactory::createOne(['area' => $area, 'module' => $module]);
        DatasetFactory::createOne([
            'area' => $area,
            'moduleSlug' => 'landcover',
            'key' => 'landcover_area',
            'kind' => DatasetKind::Series,
            'columns' => ['class', 'area_km2', 'pct'],
            'rows' => [['Grassland', 5123.4, 61.8], ['Cropland', 12.0, 0.1]],
        ]);
        $viz = VisualizationFactory::createOne([
            'areaModule' => $areaModule,
            'type' => VizType::Bar,
            'datasetKey' => 'landcover_area',
            'xAxis' => 'class',
            'yAxis' => 'area_km2',
        ]);

        $svg = $this->renderer()->render($area, $viz);

        self::assertNotNull($svg);
        self::assertStringContainsString('<svg class="ch"', $svg, 'renders in the app chart dialect');
        self::assertStringContainsString('<rect', $svg, 'a bar chart draws rects');
        self::assertStringContainsString('Grassland', $svg, 'x column values become the bar labels');
        self::assertStringContainsString('Cropland', $svg);
    }

    public function testUnboundVisualizationRendersNothing(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $areaModule = AreaModuleFactory::createOne(['area' => $area, 'module' => ModuleFactory::createOne(['slug' => 'landcover'])]);
        $viz = VisualizationFactory::createOne(['areaModule' => $areaModule, 'datasetKey' => null]);

        self::assertNull($this->renderer()->render($area, $viz), 'an unbound viz has nothing to plot');
    }

    public function testMissingDatasetOrUnknownColumnRendersNothing(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $areaModule = AreaModuleFactory::createOne(['area' => $area, 'module' => ModuleFactory::createOne(['slug' => 'landcover'])]);

        // Bound to a dataset key that was never ingested.
        $orphan = VisualizationFactory::createOne(['areaModule' => $areaModule, 'datasetKey' => 'never_ingested', 'xAxis' => 'class', 'yAxis' => 'area_km2']);
        self::assertNull($this->renderer()->render($area, $orphan), 'no dataset → scaffold');

        // Dataset exists but the viz binds a column it doesn't have.
        DatasetFactory::createOne(['area' => $area, 'moduleSlug' => 'landcover', 'key' => 'landcover_area', 'columns' => ['class', 'area_km2'], 'rows' => [['Grassland', 5000.5]]]);
        $badColumn = VisualizationFactory::createOne(['areaModule' => $areaModule, 'datasetKey' => 'landcover_area', 'xAxis' => 'class', 'yAxis' => 'does_not_exist']);
        self::assertNull($this->renderer()->render($area, $badColumn), 'a column that is not in the dataset → scaffold');
    }
}
