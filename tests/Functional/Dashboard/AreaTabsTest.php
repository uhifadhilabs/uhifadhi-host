<?php

declare(strict_types=1);

namespace App\Tests\Functional\Dashboard;

use App\Composition\Enum\ModuleCategory;
use App\Composition\Enum\ModuleStatus;
use App\Composition\Factory\AreaModuleFactory;
use App\Composition\Factory\ModuleFactory;
use App\Ingestion\Enum\DatasetKind;
use App\Ingestion\Factory\DatasetFactory;
use App\Spatial\Entity\AreaOfInterest;
use App\Spatial\Factory\AreaOfInterestFactory;
use App\Tests\Functional\AuthenticatedWebTestCase;

/**
 * The area's top-level tabs (Overview / Modules / Settings) and the Modules-tab card grid: the
 * Overview carries the tabs, the Modules tab shows every active module as a card that opens the
 * module's own page, and Settings is its own permission-gated page.
 */
final class AreaTabsTest extends AuthenticatedWebTestCase
{
    public function testTheOverviewCarriesTheAreaTabs(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Tabbed area']);

        $client->request('GET', '/areas/'.$area->getUuidString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.atabs a.on', 'Overview');
        self::assertSelectorExists(\sprintf('.atabs a[href$="/%s/modules"]', $area->getUuidString()));
        self::assertSelectorExists(\sprintf('.atabs a[href$="/%s/settings"]', $area->getUuidString()));
    }

    public function testTheModulesTabShowsCardsThatOpenTheModulePages(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Grid area']);
        $this->compose($area, 'overview', 'Overview', ModuleStatus::Hub, ModuleCategory::Hub, 0, pinned: true);
        $this->compose($area, 'forest', 'Forest loss', ModuleStatus::Live, ModuleCategory::Flux, 1);
        $this->compose($area, 'roads', 'Roads', ModuleStatus::Template, ModuleCategory::Pressure, 2);
        DatasetFactory::createOne([
            'area' => $area, 'moduleSlug' => 'forest', 'key' => 'forest_loss_year',
            'kind' => DatasetKind::Series, 'columns' => ['year', 'ha', 'cumulative_ha'],
            'rows' => [[2013, 186.0, 186.0]],
        ]);

        $client->request('GET', '/areas/'.$area->getUuidString().'/modules');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.atabs a.on', 'Modules');
        // Two zone headings, and a card per active module (the pinned Overview is not a card).
        self::assertSelectorTextContains('.zone', 'Flux');
        self::assertSelectorExists(\sprintf('.mtile[href$="/%s/forest"]', $area->getUuidString()));
        self::assertSelectorExists(\sprintf('.mtile[href$="/%s/roads"]', $area->getUuidString()));
        self::assertSelectorNotExists(\sprintf('.mtile[href$="/%s/overview"]', $area->getUuidString()));
        // The live Forest card previews its real headline number; a template card stays chip-only.
        self::assertSelectorTextContains('.mtile[href$="/forest"] .mtile-stat .val', '186');
        // The Customize action reaches the module shop (module.create).
        self::assertSelectorExists(\sprintf('.pgact a[href$="/%s/modules/customize"]', $area->getUuidString()));
    }

    public function testTheSettingsTabIsItsOwnPage(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Kitulo Plateau']);

        $client->request('GET', '/areas/'.$area->getUuidString().'/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.atabs a.on', 'Settings');
        self::assertSelectorTextContains('body', 'Area identity');
        self::assertSelectorTextContains('body', 'Kitulo Plateau');
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
