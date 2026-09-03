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
use Uhifadhi\Enum\TeamRoleEnum;
use Uhifadhi\Factory\AreaModuleFactory;
use Uhifadhi\Factory\AreaOfInterestFactory;
use Uhifadhi\Factory\DepartmentFactory;
use Uhifadhi\Factory\ModuleFactory;
use Uhifadhi\Factory\PositionFactory;
use Uhifadhi\Factory\UserFactory;
use Uhifadhi\Seam\Entity\Module;
use Uhifadhi\Seam\Enum\ModuleCategory;
use Uhifadhi\Seam\Enum\ModuleStatus;

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

    /**
     * The lens on the real surface: the modules of the viewer's department lead the grid, and the
     * leading group says which department put them there. Nothing is hidden — every other module
     * follows, in the order the area already had.
     */
    public function testTheModulesGridIsLedByTheViewersDepartment(): void
    {
        $client = static::createClient();
        $area = AreaOfInterestFactory::createOne(['name' => 'Lens area']);
        $patrols = $this->compose($area, 'patrols', 'Patrols', ModuleStatus::Live, ModuleCategory::Pressure, 1);
        $this->compose($area, 'wildlife', 'Wildlife', ModuleStatus::Template, ModuleCategory::Flux, 2);
        $protection = DepartmentFactory::createOne(['name' => 'Protection Service', 'modules' => [$patrols]]);
        $client->loginUser(UserFactory::createOne([
            'teamRole' => TeamRoleEnum::Admin,
            'position' => PositionFactory::new(['name' => 'Ranger', 'department' => $protection]),
        ]));

        $crawler = $client->request('GET', '/areas/'.$area->getUuidString().'/modules');

        self::assertResponseIsSuccessful();
        // Patrols is a Pressure module, which the zone order puts second — the lens leads with it.
        self::assertSame('Patrols', trim($crawler->filter('.mtile-title')->first()->text()));
        self::assertSelectorTextContains('.zone .lens-led', 'led by Protection Service');
        // A lens, not a fence: everything else is still on the page, in its own zone.
        self::assertCount(2, $crawler->filter('.mtile-title'));
        self::assertStringContainsString('Wildlife', $crawler->filter('.grid')->last()->text());
    }

    public function testAViewerWithoutADepartmentSeesTheGridUnchanged(): void
    {
        $client = static::createClient();
        $area = AreaOfInterestFactory::createOne(['name' => 'Lensless area']);
        $this->compose($area, 'patrols', 'Patrols', ModuleStatus::Live, ModuleCategory::Pressure, 1);
        $this->compose($area, 'wildlife', 'Wildlife', ModuleStatus::Template, ModuleCategory::Flux, 2);
        $this->loginAs($client);

        $crawler = $client->request('GET', '/areas/'.$area->getUuidString().'/modules');

        self::assertResponseIsSuccessful();
        // Today's order, untouched: the zones in reading order, Flux first.
        self::assertSame('Wildlife', trim($crawler->filter('.mtile-title')->first()->text()));
        self::assertSelectorNotExists('.lens-led', 'no department leads, so nothing claims to');
    }

    private function compose(
        AreaOfInterest $area,
        string $slug,
        string $name,
        ModuleStatus $status,
        ModuleCategory $category,
        int $position,
        bool $pinned = false,
    ): Module {
        $areaModule = AreaModuleFactory::createOne([
            'area' => $area, 'active' => true, 'position' => $position,
            'module' => ModuleFactory::new([
                'slug' => $slug, 'name' => $name, 'status' => $status,
                'category' => $category, 'pinned' => $pinned, 'dataSource' => 'Hansen GFC',
            ]),
        ]);

        $module = $areaModule->getModule();
        \assert($module instanceof Module);

        return $module;
    }
}
