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
use Uhifadhi\Factory\AreaModuleFactory;
use Uhifadhi\Factory\AreaOfInterestFactory;
use Uhifadhi\Factory\ModuleFactory;
use Uhifadhi\Seam\Enum\ModuleCategory;
use Uhifadhi\Seam\Enum\ModuleStatus;

/**
 * The one sidebar: grouped sections — OBSERVATORY / ORGANIZATION / SYSTEM — and the location
 * TREE under Areas. The tree states where you are: every area is a child row, the area you are
 * in unfolds to its real screens, and the module you are inside nests under Modules. Outside
 * the areas section the whole tree is folded away (still openable).
 *
 * WHAT THIS TESTS AND WHAT IT DOES NOT. The tree's markup belongs to uhifadhi/shell-module and
 * is pinned by that package's own suite: one row grammar at every depth, `closed` as the only
 * fold state, a caret on every row that has children. What is tested HERE is the part this
 * application decides and hands over — which areas, which screens, which modules, and which
 * single row is lit. The two are checked separately on purpose: a rename in the shell's markup
 * is that package's release to make, and this file should fail loudly when it happens.
 */
final class SidebarNavTest extends AuthenticatedWebTestCase
{
    public function testTheRegisterOpensTheTreeAndListsEveryArea(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        AreaOfInterestFactory::createOne(['name' => 'Amboni Caves']);
        AreaOfInterestFactory::createOne(['name' => 'Ngorongoro']);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        // Section labels group the org level (they are not links).
        self::assertSame(
            ['Observatory', 'Organization', 'System'],
            $crawler->filter('.side .nav-hd')->each(static fn ($node): string => trim($node->text())),
        );
        // Inside the areas section the tree is unfolded…
        self::assertSelectorExists('.side .ntree');
        self::assertSelectorNotExists('.side .ntree.closed');
        // …with EVERY area as a child row, in repository order.
        self::assertSame(
            ['Amboni Caves', 'Ngorongoro'],
            $crawler->filter('.side .ntree .nta b')->each(static fn ($node): string => trim($node->text())),
        );
        // No area is current here, so every area's own tab group stays folded — and each row
        // carries the caret that unfolds it.
        self::assertCount(2, $crawler->filter('.side .ntree .nta-group.closed'));
        self::assertCount(2, $crawler->filter('.side .ntree .nta .chev[data-action*="sidebar-tree#fold"]'));
        self::assertSelectorTextContains('.side .nav-item.on', 'Areas');
    }

    public function testTheCurrentAreaUnfoldsToItsRealTabs(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Tree area']);
        AreaOfInterestFactory::createOne(['name' => 'Other area']);

        $crawler = $client->request('GET', '/areas/'.$area->getUuidString());

        self::assertResponseIsSuccessful();
        // The area you are in is the current row and its tab group is open; the other stays folded.
        self::assertSelectorTextContains('.side .ntree .nta.on b', 'Tree area');
        self::assertCount(1, $crawler->filter('.side .ntree .nta-group.closed'));
        // The tabs are the area's real tab row, in the order the area page states it.
        self::assertSame(
            ['Overview', 'Modules', 'Zones', 'Settings'],
            $crawler->filter('.side .ntree .nta-group:not(.closed) > .ntt')
                ->each(static fn ($node): string => trim($node->text())),
        );
        // An area with no module switched on has nothing to hang under Modules, so Modules is
        // a plain tab here — no caret, no child group.
        self::assertSelectorTextContains('.side .ntree .nta-group > .ntt.on', 'Overview');
        self::assertSelectorNotExists('.side .ntree .ntgroup');
        self::assertSelectorNotExists('.side .ntree .ntt .chev');
    }

    /**
     * On the modules page the Modules row is lit AND carries a branch, and that branch is open,
     * with one row per module the area actually has pages for.
     */
    public function testTheModulesPageShowsModulesAsAnOpenParentListingEveryModule(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Composed area']);
        $this->compose($area, 'patrols', 'Patrols', 1);
        $this->compose($area, 'incidents', 'Incidents', 2);

        $crawler = $client->request('GET', '/areas/'.$area->getUuidString().'/modules');

        self::assertResponseIsSuccessful();
        // Lit AND a parent, with the caret that folds its children.
        self::assertSelectorTextContains('.side .ntree .ntt.on', 'Modules');
        self::assertSelectorExists('.side .ntree .ntt .chev[data-action*="sidebar-tree#fold"]');
        // The children are here and unfolded, in the area's own module order, each with its dot.
        self::assertSelectorNotExists('.side .ntree .ntgroup.closed');
        self::assertSelectorNotExists('.side .ntree .ntt.closed');
        self::assertSame(
            ['Patrols', 'Incidents'],
            $crawler->filter('.side .ntree .ntgroup > .ntt')->each(static fn ($node): string => trim($node->text())),
        );
        // You are on the grid, not in a module: no child claims the active state.
        self::assertSelectorNotExists('.side .ntree .ntgroup > .ntt.on');
    }

    /**
     * Same grammar on the area's hub: the Modules row still carries its branch and the branch is
     * folded — the children stay in the DOM, so the caret can reopen them.
     */
    public function testTheOverviewPageKeepsModulesAParentWithItsChildrenFolded(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Composed area']);
        $this->compose($area, 'patrols', 'Patrols', 1);

        $crawler = $client->request('GET', '/areas/'.$area->getUuidString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.side .ntree .ntt.closed', 'Modules');
        self::assertSelectorExists('.side .ntree .ntgroup.closed');
        self::assertCount(1, $crawler->filter('.side .ntree .ntgroup > .ntt'));
        self::assertSelectorTextContains('.side .ntree .nta-group > .ntt.on', 'Overview');
    }

    /**
     * Inside a module the whole catalogue still hangs under Modules — only the one you are in
     * takes `.on` (areas/ngorongoro/modules/patrols/index.html).
     */
    public function testOnlyTheModuleYouAreInsideIsActive(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Composed area']);
        $this->compose($area, 'patrols', 'Patrols', 1);
        $this->compose($area, 'incidents', 'Incidents', 2);

        $crawler = $client->request('GET', '/areas/'.$area->getUuidString().'/modules/patrols');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.side .ntree .ntgroup > .ntt'));
        self::assertCount(1, $crawler->filter('.side .ntree .ntgroup > .ntt.on'));
        self::assertSelectorTextContains('.side .ntree .ntgroup > .ntt.on', 'Patrols');
        // The parent shows the child; it never steals its active state.
        self::assertSelectorTextContains('.side .ntree .ntt:has(.chev)', 'Modules');
        self::assertSelectorNotExists('.side .ntree .nta-group > .ntt.on');
    }

    public function testInsideAModuleThatModuleNestsUnderModules(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Module area']);
        $this->compose($area, 'patrols', 'Patrols');

        $crawler = $client->request('GET', '/areas/'.$area->getUuidString().'/modules/patrols');

        self::assertResponseIsSuccessful();
        // Modules takes the parent affordance (weight + caret) and its child group is open.
        self::assertSelectorTextContains('.side .ntree .ntt:has(.chev)', 'Modules');
        self::assertSelectorNotExists('.side .ntree .ntgroup.closed');
        // The module is the level-3 entry, active, with its identity dot — and the tab row above
        // it is NOT claimed as the active leaf.
        self::assertSelectorTextContains('.side .ntree .ntgroup > .ntt.on', 'Patrols');
        self::assertSelectorExists(\sprintf(
            '.side .ntree .ntgroup > .ntt[href$="/%s/modules/patrols"]',
            $area->getUuidString(),
        ));
        self::assertSelectorNotExists('.side .ntree .nta-group > .ntt.on');
        self::assertCount(1, $crawler->filter('.side .ntree .ntgroup > .ntt'));
    }

    public function testADepartmentsPageFoldsTheTreeAway(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        AreaOfInterestFactory::createOne(['name' => 'Unlisted here']);

        $crawler = $client->request('GET', '/departments');

        self::assertResponseIsSuccessful();
        // Outside the areas section the tree is folded — present in the DOM, so it reopens.
        self::assertSelectorExists('.side .ntree.closed');
        self::assertSelectorExists('.side .nav-item.closed .chev[data-action*="sidebar-tree#fold"]');
        self::assertSelectorTextContains('.side .nav-item.on', 'Departments');
        self::assertCount(1, $crawler->filter('.side .nav-item.on'));
    }

    public function testThePerformanceBoardLightsItsOwnItem(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        // An area to list, so that "the tree is folded away" is a statement
        // about folding rather than about there being nothing to fold.
        AreaOfInterestFactory::createOne(['name' => 'Unlisted here too']);

        $crawler = $client->request('GET', '/departments/performance');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.side .nav-item.on', 'Performance');
        self::assertCount(1, $crawler->filter('.side .nav-item.on'));
        self::assertSelectorExists('.side .ntree.closed');
    }

    /**
     * An area's Zones tab is a real route now, so the tree draws it — and the tab row above is
     * the area's own, in the order the area page states it.
     */
    public function testTheZonesTabIsInTheTree(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Zoned area']);

        $crawler = $client->request('GET', '/areas/'.$area->getUuidString().'/zones');

        self::assertResponseIsSuccessful();
        self::assertContains('Zones', $crawler->filter('.side .ntree .nta-group:not(.closed) > .ntt')
            ->each(static fn ($node): string => trim($node->text())));
        self::assertSelectorTextContains('.side .ntree .nta-group > .ntt.on', 'Zones');
    }

    private function compose(AreaOfInterest $area, string $slug, string $name, int $position = 1): void
    {
        AreaModuleFactory::createOne([
            'area' => $area,
            'module' => ModuleFactory::new([
                'slug' => $slug,
                'name' => $name,
                'status' => ModuleStatus::Live,
                'category' => ModuleCategory::Pressure,
            ]),
            'active' => true,
            'position' => $position,
        ]);
    }
}
