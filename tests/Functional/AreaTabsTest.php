<?php

declare(strict_types=1);

/*
 * This file is part of uhifadhi.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Tests\Functional;

use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Enum\ModuleCategory;
use Uhifadhi\Enum\ModuleStatus;
use Uhifadhi\Factory\AreaModuleFactory;
use Uhifadhi\Factory\AreaOfInterestFactory;
use Uhifadhi\Factory\ModuleFactory;

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
        $this->compose($area, 'patrols', 'Patrols', ModuleStatus::Live, ModuleCategory::Pressure, 1);
        $this->compose($area, 'blank', 'Blank module', ModuleStatus::Template, ModuleCategory::Pressure, 2);
        $client->request('GET', '/areas/'.$area->getUuidString().'/modules');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.atabs a.on', 'Modules');
        // Two zone headings, and a card per active module (the pinned Overview is not a card).
        self::assertSelectorTextContains('.zone', 'Pressure');
        // A provider module links to its own entry route (area-nested).
        self::assertSelectorExists(\sprintf('a.mtile[href$="/%s/modules/patrols"]', $area->getUuidString()));
        // A catalogue row without pages renders an inert tile, never a dead link.
        self::assertSelectorExists('.mtile-inert');
        self::assertSelectorNotExists(\sprintf('.mtile[href$="/%s/overview"]', $area->getUuidString()));
        // Cards carry catalogue identity only — richer content lives on the module's own pages.
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
