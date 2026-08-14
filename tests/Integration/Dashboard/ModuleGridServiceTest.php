<?php

declare(strict_types=1);

namespace App\Tests\Integration\Dashboard;

use App\Composition\Enum\ModuleCategory;
use App\Composition\Enum\ModuleStatus;
use App\Composition\Factory\AreaModuleFactory;
use App\Composition\Factory\ModuleFactory;
use App\Composition\Service\AreaCompositionService;
use App\Dashboard\Service\AreaModuleService;
use App\Dashboard\Service\ModuleGridService;
use App\Forest\Factory\ForestLossYearFactory;
use App\Forest\Service\ForestLossSummaryService;
use App\Spatial\Entity\AreaOfInterest;
use App\Spatial\Factory\AreaOfInterestFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The Modules-tab grid: active modules become cards grouped by the flux/pressure/biodiversity zones;
 * the pinned Overview hub is never a card; the live Forest module carries a real stat + series, while
 * template modules carry none.
 */
final class ModuleGridServiceTest extends KernelTestCase
{
    use Factories;

    private function service(): ModuleGridService
    {
        $container = self::getContainer();
        $composition = $container->get(AreaCompositionService::class);
        $areaModules = $container->get(AreaModuleService::class);
        $forestLoss = $container->get(ForestLossSummaryService::class);
        \assert($composition instanceof AreaCompositionService);
        \assert($areaModules instanceof AreaModuleService);
        \assert($forestLoss instanceof ForestLossSummaryService);

        return new ModuleGridService($composition, $areaModules, $forestLoss);
    }

    public function testGroupsActiveModulesByZoneAndDropsThePinnedHub(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $this->compose($area, 'overview', 'Overview', ModuleStatus::Hub, ModuleCategory::Hub, 0, pinned: true);
        $this->compose($area, 'forest', 'Forest loss', ModuleStatus::Live, ModuleCategory::Flux, 1);
        $this->compose($area, 'roads', 'Roads', ModuleStatus::Template, ModuleCategory::Pressure, 2);

        $groups = $this->service()->grouped($area);

        // Two zones (Flux, Pressure), in order; the pinned Overview produced no card.
        self::assertSame(
            ['Flux — what the ecosystem is doing', 'Pressure — what people are doing'],
            array_column($groups, 'label'),
        );
        self::assertSame(['forest'], array_column($groups[0]['cards'], 'slug'));
        self::assertSame(['roads'], array_column($groups[1]['cards'], 'slug'));
    }

    public function testTheLiveForestCardCarriesRealStatAndSeriesWhileTemplatesDoNot(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $this->compose($area, 'forest', 'Forest loss', ModuleStatus::Live, ModuleCategory::Flux, 0);
        $this->compose($area, 'landcover', 'Land cover', ModuleStatus::Template, ModuleCategory::Flux, 1);
        ForestLossYearFactory::createOne(['aoi' => $area, 'year' => 2013, 'areaHa' => 186.0]);
        ForestLossYearFactory::createOne(['aoi' => $area, 'year' => 2014, 'areaHa' => 120.0]);

        $cards = $this->service()->grouped($area)[0]['cards'];
        $forest = $cards[array_search('forest', array_column($cards, 'slug'), true)];
        $landcover = $cards[array_search('landcover', array_column($cards, 'slug'), true)];

        self::assertSame(306, $forest['stat']); // 186 + 120
        self::assertSame('ha lost · 13–14', $forest['statSub']);
        self::assertSame([186.0, 120.0], $forest['series']);
        self::assertSame('live', $forest['status']);

        // A template module: honest — a summary and source, but no fabricated numbers.
        self::assertNull($landcover['stat']);
        self::assertSame([], $landcover['series']);
        self::assertNotSame('', $landcover['summary']);
    }

    private function compose(
        AreaOfInterest $area,
        string $slug,
        string $name,
        ModuleStatus $status,
        ModuleCategory $category,
        int $position,
        bool $pinned = false,
    ): void {
        AreaModuleFactory::createOne([
            'area' => $area, 'active' => true, 'position' => $position,
            'module' => ModuleFactory::new([
                'slug' => $slug, 'name' => $name, 'status' => $status,
                'category' => $category, 'pinned' => $pinned, 'dataSource' => 'Hansen GFC',
            ]),
        ]);
    }
}
