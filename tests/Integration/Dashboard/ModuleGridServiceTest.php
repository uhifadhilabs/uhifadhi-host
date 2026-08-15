<?php

declare(strict_types=1);

namespace App\Tests\Integration\Dashboard;

use App\Composition\Enum\ModuleCategory;
use App\Composition\Enum\ModuleStatus;
use App\Composition\Factory\AreaModuleFactory;
use App\Composition\Factory\ModuleFactory;
use App\Composition\Service\AreaCompositionService;
use App\Dashboard\Module\ModuleRegistry;
use App\Dashboard\Service\AreaModuleService;
use App\Dashboard\Service\ModuleGridService;
use App\Ingestion\Enum\DatasetKind;
use App\Ingestion\Factory\DatasetFactory;
use App\Ingestion\Repository\DatasetRepository;
use App\Spatial\Entity\AreaOfInterest;
use App\Spatial\Factory\AreaOfInterestFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The Modules-tab grid, module-blind: cards group by zone; the pinned hub is never a card; a module's
 * headline stat is its DEFINITION's first KPI (computed in its own context) and its spark comes from
 * its first tabular dataset — modules without either stay honest (no fabricated numbers).
 */
final class ModuleGridServiceTest extends KernelTestCase
{
    use Factories;

    private function service(): ModuleGridService
    {
        $c = self::getContainer();
        $composition = $c->get(AreaCompositionService::class);
        $areaModules = $c->get(AreaModuleService::class);
        $registry = $c->get(ModuleRegistry::class);
        $datasets = $c->get(DatasetRepository::class);
        \assert($composition instanceof AreaCompositionService);
        \assert($areaModules instanceof AreaModuleService);
        \assert($registry instanceof ModuleRegistry);
        \assert($datasets instanceof DatasetRepository);

        return new ModuleGridService($composition, $areaModules, $registry, $datasets);
    }

    public function testGroupsActiveModulesByZoneAndDropsThePinnedHub(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $this->compose($area, 'overview', 'Overview', ModuleStatus::Hub, ModuleCategory::Hub, 0, pinned: true);
        $this->compose($area, 'forest', 'Forest loss', ModuleStatus::Live, ModuleCategory::Flux, 1);
        $this->compose($area, 'roads', 'Roads', ModuleStatus::Template, ModuleCategory::Pressure, 2);

        $groups = $this->service()->grouped($area);

        self::assertSame(
            ['Flux — what the ecosystem is doing', 'Pressure — what people are doing'],
            array_column($groups, 'label'),
        );
        self::assertSame(['forest'], array_column($groups[0]['cards'], 'slug'));
        self::assertSame(['roads'], array_column($groups[1]['cards'], 'slug'));
    }

    public function testStatComesFromTheModulesDefinitionAndSparkFromItsDataset(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $this->compose($area, 'forest', 'Forest loss', ModuleStatus::Live, ModuleCategory::Flux, 0);
        $this->compose($area, 'landcover', 'Land cover', ModuleStatus::Template, ModuleCategory::Flux, 1);
        DatasetFactory::createOne([
            'area' => $area, 'moduleSlug' => 'forest', 'key' => 'forest_loss_year',
            'kind' => DatasetKind::Series, 'columns' => ['year', 'ha', 'cumulative_ha'],
            'rows' => [[2013, 186.0, 186.0], [2014, 120.0, 306.0]],
        ]);

        $cards = $this->service()->grouped($area)[0]['cards'];
        $forest = $cards[array_search('forest', array_column($cards, 'slug'), true)];
        $landcover = $cards[array_search('landcover', array_column($cards, 'slug'), true)];

        // ForestModule's first KPI (computed in App\Forest) becomes the card stat.
        self::assertSame('306 ha', $forest['stat']);
        self::assertSame('2013–2014 · real', $forest['statSub']);
        // The spark is column 1 (the value column) of its first tabular dataset.
        self::assertSame([186.0, 120.0], $forest['series']);

        // Landcover ships no KPIs and has no dataset here: honest — no numbers.
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
