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

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Entity\Department;
use Uhifadhi\Entity\Position;
use Uhifadhi\Enum\PermissionEnum;
use Uhifadhi\Enum\TeamRoleEnum;
use Uhifadhi\Factory\DepartmentFactory;
use Uhifadhi\Factory\PositionFactory;
use Uhifadhi\Factory\UserFactory;
use Uhifadhi\Model\TeamWidgets;
use Uhifadhi\Model\WidgetDom;

/**
 * The org-wide TEAM surface: the widget dashboard, its library, the writes behind the library,
 * and — the point of the whole screen — that every one of the five Positions directions renders
 * the TWIN-ANALYST fixture unambiguously.
 *
 * THE FIXTURE. Two departments, each owning a position called "Analyst" with different
 * permissions, plus one unfiled position. It is the case the entire design argument exists for:
 * a screen that lets a reader merge those two rows has failed, whichever of the five layouts is
 * on. So each direction is asked the same question — can I tell the two apart? — and each
 * answers it its own way: a band, a scope bar, a card, a qualified name, a pane.
 */
final class TeamSurfaceTest extends AuthenticatedWebTestCase
{
    // ---------------------------------------------------------------- dashboard

    public function testTheDashboardRendersOnlyTheWidgetsThatAreOnByDefault(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);
        $this->twinAnalysts();

        $crawler = $client->request('GET', '/team');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.w-grid');
        // People and the grouped table are the catalogue's defaults; the other four directions
        // are ALTERNATIVES to the grouped table and must not be on beside it.
        foreach (['people', 'positions_a'] as $on) {
            self::assertCount(1, $crawler->filter('.w-grid > [data-widget-id="'.$on.'"]'), $on.' is on the dashboard');
        }
        foreach (['positions_b', 'positions_c', 'positions_d', 'positions_e'] as $off) {
            self::assertCount(0, $crawler->filter('.w-grid > [data-widget-id="'.$off.'"]'), $off.' is not on the dashboard');
        }
    }

    public function testTheDashboardStatesEachWidgetsSpanAsAClassAndAnAttribute(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);

        $crawler = $client->request('GET', '/team');

        $cell = $crawler->filter('.w-grid > [data-widget-id="people"]');
        // w-span-12, never a bare w-12: that is Tailwind's width utility, and it out-cascades
        // the grid rule — a full-width widget rendered 48px wide.
        self::assertStringContainsString('w-span-12', (string) $cell->attr('class'));
        self::assertSame('12', $cell->attr(WidgetDom::COLS));
    }

    // ------------------------------------------------- the twin-Analyst reading

    /**
     * Every direction, asked the same question: are the two Analysts distinguishable?
     */
    public function testThePeopleRosterWritesEveryPositionDepartmentFirst(): void
    {
        $client = static::createClient();
        [$ecology, $protection] = $this->twinAnalysts();
        UserFactory::createOne([
            'teamRole' => TeamRoleEnum::Staff,
            'position' => $this->position('Analyst', $ecology),
        ]);
        $this->loginAs($client, TeamRoleEnum::Manager);

        $crawler = $client->request('GET', '/team');

        // The holder's own position cell: department, separator, name.
        $qualified = $crawler->filter('[data-people] tbody .qual')->first();
        self::assertSame('Ecology', $qualified->filter('.q')->text());
        self::assertSame('Analyst', $qualified->filter('.n')->text());

        // And the dropdown they would be reassigned through writes both Analysts the same way,
        // so neither can be picked for the other.
        $options = $crawler->filter('[data-people] select[name="position"] option')->each(
            static fn (Crawler $node): string => trim($node->text()),
        );
        self::assertContains('Ecology / Analyst', $options);
        self::assertContains('Protection Service / Analyst', $options);
        self::assertNotContains('Analyst', $options, 'a bare name would name two different positions');
        self::assertSame($protection->getName(), 'Protection Service');
    }

    public function testTheGroupedTableShowsEachAnalystOnlyInsideItsOwnDepartmentBand(): void
    {
        $crawler = $this->widget('positions_a');

        $bands = $crawler->filter('[data-positions="a"] tr.gh .ghead b')->each(
            static fn (Crawler $node): string => trim($node->text()),
        );
        self::assertSame(['Ecology', 'Protection Service', 'Unfiled — no department yet'], $bands);

        // Two Analysts, each drawn bare — the band above it is what states the department.
        $names = $crawler->filter('[data-positions="a"] tr.prow td.pname > a')->each(
            static fn (Crawler $node): string => trim($node->text()),
        );
        self::assertSame(['Analyst', 'Field Officer', 'Analyst', 'Ranger', 'Park Manager'], $names);
        self::assertCount(2, $crawler->filter('[data-positions="a"] tr.prow .dupflag'), 'both twins are flagged');
    }

    public function testEveryGroupedTableBandCarriesItsOwnCreateRowSoTheDepartmentIsNeverForgotten(): void
    {
        $crawler = $this->widget('positions_a');

        $rows = $crawler->filter('[data-positions="a"] tr.newrow');
        self::assertCount(3, $rows, 'one create row per band, including the unfiled holding pen');

        // The department is a HIDDEN field on the row, decided by where the row sits — there is
        // no select to leave unset, which is the whole reason this is the default direction.
        $departments = $rows->filter('input[name="department"]')->each(
            static fn (Crawler $node): string => (string) $node->attr('value'),
        );
        self::assertCount(3, $departments);
        self::assertSame('', $departments[2], 'the unfiled band posts an empty department, explicitly');
        self::assertNotSame($departments[0], $departments[1]);

        // And no direction offers an inline "change this position's department" select.
        self::assertCount(0, $crawler->filter('[data-positions="a"] tr.prow select[name="department"]:not([required])'));
    }

    public function testTheFilterChipsScopeTheTableThroughALinkableUrl(): void
    {
        $client = static::createClient();
        [$ecology] = $this->twinAnalysts();
        $this->loginAs($client, TeamRoleEnum::Manager);
        $this->turnOn($client, 'positions_b');

        // "All": every name is written department-first.
        $crawler = $client->request('GET', '/team');
        self::assertSame('all', $crawler->filter('[data-positions="b"]')->attr('data-f'));
        self::assertCount(5, $crawler->filter('[data-positions="b"] tbody tr'));
        self::assertCount(2, $crawler->filter('[data-positions="b"] tbody .qual .q:contains("Ecology")'));

        // Scoped: the URL says which department, and only that department's rows are drawn.
        $crawler = $client->request('GET', '/team?department='.$ecology->getUuidString());
        self::assertSame((string) $ecology->getUuidString(), $crawler->filter('[data-positions="b"]')->attr('data-f'));
        self::assertCount(2, $crawler->filter('[data-positions="b"] tbody tr'));
        self::assertStringContainsString('Ecology', $crawler->filter('[data-positions="b"] .scopebar.one')->text());
        self::assertStringContainsString('unique in', $crawler->filter('[data-positions="b"] .scopebar.one')->text());
    }

    public function testTheDepartmentCardsPutTheTwoAnalystsInTwoDifferentCards(): void
    {
        $crawler = $this->widget('positions_c');

        $cards = $crawler->filter('[data-positions="c"] .dcard');
        self::assertCount(3, $cards, 'a card per department, plus the unfiled holding pen');
        self::assertSame('Ecology', trim($cards->eq(0)->filter('.dh-l b')->text()));
        self::assertSame('Protection Service', trim($cards->eq(1)->filter('.dh-l b')->text()));

        // One Analyst in each card, and never two in one.
        foreach ([0, 1] as $index) {
            self::assertCount(1, $cards->eq($index)->filter('.pitem .pn a:contains("Analyst")'));
        }
        // The add control on each card already carries its own department.
        self::assertCount(3, $crawler->filter('[data-positions="c"] .addpos input[name="department"]'));
    }

    public function testTheQualifiedListWritesTheDepartmentInBothTheColumnAndTheName(): void
    {
        $crawler = $this->widget('positions_d');

        $rows = $crawler->filter('[data-positions="d"] tbody tr');
        self::assertCount(5, $rows);
        self::assertSame('Ecology', trim($rows->eq(0)->filter('.dcell .dtag')->text()));
        self::assertSame('Ecology', $rows->eq(0)->filter('.qual .q')->text());
        self::assertSame('Analyst', $rows->eq(0)->filter('.qual .n')->text());

        // Department is FIELD ONE of the create form, and it is required — the uniqueness check
        // runs against the chosen department only, so the name cannot be answered before it.
        $fields = $crawler->filter('[data-positions="d"] .cform .cfield .lbl')->each(
            static fn (Crawler $node): string => trim($node->text()),
        );
        self::assertStringStartsWith('1Department', $fields[0]);
        self::assertStringStartsWith('2Name', $fields[1]);
        self::assertNotNull($crawler->filter('[data-positions="d"] .cform select[name="department"]')->attr('required'));
    }

    public function testTheSplitManagerShowsOneDepartmentAtATimeAndSaysWhich(): void
    {
        $client = static::createClient();
        [, $protection] = $this->twinAnalysts();
        $this->loginAs($client, TeamRoleEnum::Manager);
        $this->turnOn($client, 'positions_e');

        // Opens on the first band, and the pane head states it.
        $crawler = $client->request('GET', '/team');
        self::assertCount(1, $crawler->filter('[data-positions="e"] .pane'), 'exactly one pane is rendered');
        self::assertStringContainsString('Ecology', $crawler->filter('[data-positions="e"] .pane .ph-l')->text());
        self::assertStringContainsString('names unique in', $crawler->filter('[data-positions="e"] .pscope')->text());
        self::assertCount(1, $crawler->filter('[data-positions="e"] .pane tbody tr td a:contains("Analyst")'));

        // The rail is navigation: the selection is in the URL and survives a reload.
        $crawler = $client->request('GET', '/team?rail='.$protection->getUuidString());
        self::assertStringContainsString('Protection Service', $crawler->filter('[data-positions="e"] .pane .ph-l')->text());
        self::assertSame(
            (string) $protection->getUuidString(),
            null !== $crawler->filter('[data-positions="e"] .rail-item.on')->attr('href')
                ? $crawler->filter('[data-positions="e"] .pane')->attr('data-pane')
                : '',
        );
    }

    // ------------------------------------------------- the page's own furniture

    /**
     * WIDGET-ID CHIPS ARE DESIGN-WORKSPACE FURNITURE, NOT PRODUCT UI.
     *
     * "PL·01", "PL·02" are the numbers the static design files carry so a reviewer can point at a
     * plate by name. They mean nothing to a ranger reading the roster, and a product screen that
     * ships them is showing the reader the scaffolding. The library page renders EVERY widget in
     * the catalogue, so asking it is asking all of them at once.
     */
    public function testNoWidgetIdChipShipsOnTheTeamSurface(): void
    {
        $client = static::createClient();
        $this->twinAnalysts();
        $this->loginAs($client, TeamRoleEnum::Manager);

        foreach (['/team', '/team/widgets', '/team/positions/new'] as $path) {
            $crawler = $client->request('GET', $path);
            self::assertResponseIsSuccessful();

            self::assertCount(0, $crawler->filter('.c .tab .idx'), $path.' ships no widget-id chip');
            self::assertDoesNotMatchRegularExpression(
                '/\bPL[·.]\d/u',
                (string) $client->getResponse()->getContent(),
                $path.' ships no PL· design reference',
            );
        }
    }

    /**
     * THE PLATE TAB MUST NOT BE CLIPPED BY ITS OWN CARD.
     *
     * The tab straddles the card's top border (`position: absolute; top: -9px`), so any scroll
     * container on the card itself cuts the title in half — `overflow-x: auto` computes
     * `overflow-y` to `auto` too, and a scroll container clips its positioned descendants. The
     * fix is the one this stylesheet already uses for wide registers (.ztblwrap): the SCROLL goes
     * on a wrapper around the table, never on the plate.
     *
     * This pins the structure. That the title then renders whole is a visual fact, checked by
     * rasterizing the page.
     */
    public function testAWideTeamTableScrollsInAWrapperRatherThanInThePlateItself(): void
    {
        $client = static::createClient();
        $this->twinAnalysts();
        $this->loginAs($client, TeamRoleEnum::Manager);

        $crawler = $client->request('GET', '/team');

        foreach (['[data-people]', '[data-positions="a"]'] as $plate) {
            self::assertCount(
                0,
                $crawler->filter($plate.' > table.tbl'),
                $plate.' does not hold its table directly — the scroll wrapper is between them',
            );
            self::assertCount(1, $crawler->filter($plate.' > .tm-tblwrap > table.tbl'), $plate.' scrolls in a wrapper');
        }
    }

    /**
     * "File…" spelled with an ellipsis promises a further step — a dialog, a second screen. There
     * is none: the button files the position there and then. It is the SAVE for the select beside
     * it, and it has to read like one.
     */
    public function testTheUnfiledRowsDepartmentSelectIsSavedByAPlainFileButton(): void
    {
        $crawler = $this->widget('positions_a');

        $form = $crawler->filter('[data-positions="a"] tr.prow form[action$="/department"]');
        self::assertCount(1, $form, 'the holding pen offers exactly one file control');
        self::assertCount(1, $form->filter('select[name="department"]'));
        self::assertSame('File', trim($form->filter('button[type="submit"]')->text()));
    }

    // ------------------------------------------------------------------ library

    public function testTheLibraryOffersTheFiveDirectionsAndDeclaresEveryWidget(): void
    {
        $client = static::createClient();
        $this->twinAnalysts();
        $this->loginAs($client, TeamRoleEnum::Manager);

        $crawler = $client->request('GET', '/team/widgets');
        self::assertResponseIsSuccessful();

        // The five directions Positions was drawn in, each adoptable whole.
        $designs = $crawler->filter('[data-presets="designs"] [data-preset-kind="design"]')->each(
            static fn (Crawler $node): string => (string) $node->attr('data-preset-id'),
        );
        self::assertSame(array_column(TeamWidgets::catalog()->builtins(), 'id'), $designs);

        // And the catalogue the library composes from names both sections and all six widgets.
        /** @var array{surface: string, groups: list<array{id: string}>, widgets: list<array{id: string}>} $declared */
        $declared = json_decode(
            $crawler->filter('script['.WidgetDom::CATALOG.']')->text(),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        self::assertSame('team', $declared['surface']);
        self::assertSame(['people', 'positions'], array_column($declared['groups'], 'id'));
        self::assertSame(TeamWidgets::catalog()->ids(), array_column($declared['widgets'], 'id'));
    }

    public function testAShippedDesignCannotBeEditedButACopyOfItCan(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);
        $token = $this->widgetToken($client);

        // Swapping the grouped table for the split manager — they are alternatives, so one goes
        // off exactly as the other comes on.
        $layout = json_encode([
            'order' => ['positions_e', 'people'],
            'widgets' => [
                'people' => ['on' => true, 'cols' => 12],
                'positions_a' => ['on' => false, 'cols' => 12],
                'positions_e' => ['on' => true, 'cols' => 12],
            ],
        ], \JSON_THROW_ON_ERROR);

        // A fresh person is on the shipped grouped table, and the product's own designs are
        // immutable — so the edit is refused rather than silently forking it.
        $client->request('POST', '/team/widgets/save', server: $this->tokenHeader($token), content: $layout);
        self::assertResponseStatusCodeSame(422);

        // The one door into an editable layout: take a copy, which becomes active.
        $client->request('POST', '/team/widgets/preset/positions_a/copy', ['_token' => $token, 'name' => 'My team view']);
        self::assertResponseRedirects('/team/widgets');

        $client->request('POST', '/team/widgets/save', server: $this->tokenHeader($token), content: $layout);
        self::assertResponseStatusCodeSame(204);

        $crawler = $client->request('GET', '/team');
        self::assertCount(1, $crawler->filter('.w-grid > [data-widget-id="positions_e"]'));
        self::assertCount(0, $crawler->filter('.w-grid > [data-widget-id="positions_a"]'));

        // Reset puts the shipped design back.
        $client->request('POST', '/team/widgets/reset', server: $this->tokenHeader($token));
        self::assertResponseStatusCodeSame(204);

        $crawler = $client->request('GET', '/team');
        self::assertCount(1, $crawler->filter('.w-grid > [data-widget-id="positions_a"]'), 'back to the shipped design');
        self::assertCount(0, $crawler->filter('.w-grid > [data-widget-id="positions_e"]'));
    }

    public function testAnUnknownDesignIsRefusedRatherThanApplied(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);

        $client->request('POST', '/team/widgets/preset/positions_z', ['_token' => $this->widgetToken($client)]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testASaveWithoutTheTokenIsRefused(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);

        $client->request('POST', '/team/widgets/save', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        self::assertResponseStatusCodeSame(403);
    }

    // -------------------------------------------------------------------- gating

    public function testStaffHaveNoAccessToAnyPartOfTheTeamSurface(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        // Team administration is Manager-and-up end to end — unlike /departments there is no
        // read-only reading of it to let anyone else through to.
        foreach (['/team', '/team/widgets', '/team/positions/new'] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(403, $path.' is Manager-and-up');
        }
    }

    // -------------------------------------------------------------------- helpers

    /**
     * The fixture the whole design argument exists for: two departments, each owning an
     * "Analyst" with its own permissions, plus one position nobody has filed yet.
     *
     * @return array{Department, Department}
     */
    private function twinAnalysts(): array
    {
        $ecology = DepartmentFactory::createOne(['name' => 'Ecology']);
        $protection = DepartmentFactory::createOne(['name' => 'Protection Service']);

        PositionFactory::createOne(['name' => 'Analyst', 'department' => $ecology])
            ->setPermissionValues([PermissionEnum::AreaView->value, PermissionEnum::ModuleView->value]);
        PositionFactory::createOne(['name' => 'Field Officer', 'department' => $ecology]);
        PositionFactory::createOne(['name' => 'Analyst', 'department' => $protection])
            ->setPermissionValues([PermissionEnum::AreaView->value, PermissionEnum::IngestionRun->value]);
        PositionFactory::createOne(['name' => 'Ranger', 'department' => $protection]);
        PositionFactory::createOne(['name' => 'Park Manager', 'department' => null]);

        return [$ecology, $protection];
    }

    private function position(string $name, Department $department): Position
    {
        $position = PositionFactory::repository()->findOneBy(['name' => $name, 'department' => $department]);
        \assert($position instanceof Position);

        return $position;
    }

    /** Render the dashboard with one direction on, and hand back its crawler. */
    private function widget(string $id): Crawler
    {
        $client = static::createClient();
        $this->twinAnalysts();
        $this->loginAs($client, TeamRoleEnum::Manager);
        $this->turnOn($client, $id);

        $crawler = $client->request('GET', '/team');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** Adopt the design that shows exactly this direction — one click, as the library offers. */
    private function turnOn(KernelBrowser $client, string $presetId): void
    {
        $client->request('POST', '/team/widgets/preset/'.$presetId, ['_token' => $this->widgetToken($client)]);
        self::assertResponseRedirects('/team');
    }

    /**
     * The library's own CSRF token, read off the library page exactly where the script reads it.
     * Minting one in the test container would prove nothing about the page: the real bug this
     * guards against is a controller that validates a token the template never rendered.
     */
    /**
     * The header the library's fetch() carries its token in, as a server array.
     *
     * @return array<string, string>
     */
    private function tokenHeader(string $token): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_'.str_replace('-', '_', strtoupper(WidgetDom::CSRF_HEADER)) => $token,
        ];
    }

    private function widgetToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/team/widgets');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('['.WidgetDom::CSRF_TOKEN.']')->attr(WidgetDom::CSRF_TOKEN);
        self::assertIsString($token);

        return $token;
    }
}
