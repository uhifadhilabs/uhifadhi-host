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
use Uhifadhi\Enum\TeamRoleEnum;
use Uhifadhi\Factory\DepartmentFactory;
use Uhifadhi\Factory\ModuleFactory;
use Uhifadhi\Model\DepartmentsWidgets;
use Uhifadhi\Model\WidgetDom;

/**
 * The org-wide departments surface: the widget dashboard, its library, and the two
 * writes behind the library. Everything here is the platform widget framework —
 * this test proves the departments surface is wired into it, not that the
 * framework's own algebra works ({@see \Uhifadhi\Tests\Unit\WidgetServiceTest}).
 */
final class DepartmentsSurfaceTest extends AuthenticatedWebTestCase
{
    public function testTheDashboardRendersOnlyTheWidgetsThatAreOnByDefault(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);
        DepartmentFactory::createOne(['name' => 'Ecology']);

        $crawler = $client->request('GET', '/departments');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.w-grid');
        // kpis, cards and registry are the catalogue's defaults; the other four are not.
        foreach (['kpis', 'cards', 'registry'] as $on) {
            self::assertCount(1, $crawler->filter('.w-grid > [data-widget-id="'.$on.'"]'), $on.' is on the dashboard');
        }
        foreach (['matrix', 'lanes', 'lens', 'shared'] as $off) {
            self::assertCount(0, $crawler->filter('.w-grid > [data-widget-id="'.$off.'"]'), $off.' is not on the dashboard');
        }
    }

    public function testTheDashboardStatesEachWidgetsSpanAsAClassAndAnAttribute(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        $crawler = $client->request('GET', '/departments');

        $cell = $crawler->filter('.w-grid > [data-widget-id="kpis"]');
        self::assertStringContainsString('w-cell', (string) $cell->attr('class'));
        // w-span-12, never a bare w-12: that is Tailwind's width utility, and it
        // out-cascades the grid rule — a full-width widget rendered 48px wide.
        self::assertStringContainsString('w-span-12', (string) $cell->attr('class'));
        self::assertSame('12', $cell->attr(WidgetDom::COLS));
    }

    public function testTheLibraryIsTheActivePresetsCanvasAndNothingElse(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        $crawler = $client->request('GET', '/departments/widgets');

        self::assertResponseIsSuccessful();
        // THE CANVAS IS THE DASHBOARD: exactly the composition, never the whole
        // catalogue and never a greyed-out ghost of something switched off. A
        // fresh person is on the surface's shipped composition — three widgets.
        self::assertCount(3, $crawler->filter('.w-canvas .w-card'));
        self::assertSame(
            ['kpis', 'cards', 'registry'],
            $crawler->filter('.w-canvas .w-card')->each(static fn ($node): string => (string) $node->attr(WidgetDom::WIDGET)),
        );
        foreach (['matrix', 'lanes', 'lens', 'shared'] as $absent) {
            self::assertCount(0, $crawler->filter('.w-canvas [data-widget-id="'.$absent.'"]'), $absent.' is absent, not dimmed');
        }
        // Every widget the catalogue ships is still on the page ONCE more, as an
        // inert cloneable template the script previews and picks from.
        self::assertCount(7, $crawler->filter('template['.WidgetDom::TEMPLATE.']'));
    }

    public function testABuiltInIsImmutableSoItsCanvasCarriesNoEditingChrome(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        $crawler = $client->request('GET', '/departments/widgets');

        // The designs the product ships are never edited, so the controls are not
        // offered at all rather than offered and then silently forking.
        self::assertCount(0, $crawler->filter('.w-canvas ['.WidgetDom::GRIP.']'));
        self::assertCount(0, $crawler->filter('.w-canvas ['.WidgetDom::SPAN.']'));
        self::assertCount(0, $crawler->filter('.w-canvas ['.WidgetDom::TOGGLE.']'));
        self::assertCount(0, $crawler->filter('[data-picker-open]'));
        self::assertCount(3, $crawler->filter('.w-canvas .w-card-read'));
        // The one door out: make a copy.
        self::assertCount(1, $crawler->filter('.w-previewbar form[action$="/copy"]'));
    }

    public function testEditingWhileABuiltInIsActiveIsRefused(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        $client->request('POST', '/departments/widgets/save', server: $this->jsonPost($client), content: (string) json_encode([
            'order' => ['matrix'],
            'widgets' => ['matrix' => ['on' => true, 'cols' => 12]] + self::allOff(),
        ]));

        // 422 rather than a silent fork: the library never offers this edit, so
        // reaching it means something bypassed the screen.
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Make a copy', (string) $client->getResponse()->getContent());
    }

    public function testTheLibraryCarriesTheFrameworkWire(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        $crawler = $client->request('GET', '/departments/widgets');

        $root = $crawler->filter('['.WidgetDom::ROOT.']');
        self::assertCount(1, $root);
        self::assertSame('/departments/widgets/save', $root->attr(WidgetDom::SAVE_URL));
        self::assertSame('/departments/widgets/reset', $root->attr(WidgetDom::RESET_URL));
        self::assertSame('/departments/widgets/presets', $root->attr(WidgetDom::PRESETS_URL));
        self::assertNotSame('', (string) $root->attr(WidgetDom::CSRF_TOKEN));
        self::assertCount(1, $crawler->filter('['.WidgetDom::NOTICE.']'));
        self::assertCount(1, $crawler->filter('['.WidgetDom::RESET.']'));

        // The preset routes are URL TEMPLATES: the component draws cards that did
        // not exist when the page was rendered, so it BUILDS their URLs from
        // these rather than reading an href off one.
        foreach ([
            WidgetDom::PRESET_URL => '/departments/widgets/preset/',
            WidgetDom::PRESET_COPY_URL => '/departments/widgets/preset/',
            WidgetDom::PRESET_APPLY_URL => '/departments/widgets/presets/',
            WidgetDom::PRESET_RENAME_URL => '/departments/widgets/presets/',
            WidgetDom::PRESET_DELETE_URL => '/departments/widgets/presets/',
        ] as $attribute => $prefix) {
            $template = (string) $root->attr($attribute);
            self::assertStringStartsWith($prefix, $template, $attribute);
            self::assertStringContainsString(WidgetDom::ID_PLACEHOLDER, $template, $attribute.' must carry the placeholder');
        }
    }

    public function testTheLibraryEmbedsTheWholeCatalogueSoAPreviewCostsNoRoundTrip(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        $crawler = $client->request('GET', '/departments/widgets');

        /** @var array{surface: string, active: array<string, string>, widgets: list<array{id: string}>, groups: list<array{id: string}>, builtins: list<array{id: string, layout: array<string, int>}>, mine: list<array{id: string}>} $catalog */
        $catalog = json_decode(
            $crawler->filter('['.WidgetDom::CATALOG.']')->text(),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        self::assertSame('departments', $catalog['surface']);
        self::assertSame(['kind' => 'design', 'id' => 'default', 'label' => 'The departments board'], $catalog['active']);
        self::assertCount(7, $catalog['widgets']);
        self::assertCount(5, $catalog['groups']);
        // Six built-ins: the five directions, led by the composition the surface
        // actually ships — in this model there is no layout that is not a preset.
        self::assertCount(6, $catalog['builtins']);
        self::assertSame([], $catalog['mine']);
        // Every layout is there, so previewing one is a re-composition, not a fetch.
        self::assertSame(['matrix' => 12, 'kpis' => 12], $catalog['builtins'][3]['layout']);
    }

    public function testTheLibraryMountsTheFrameworkScript(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        $client->request('GET', '/departments/widgets');

        self::assertStringContainsString(
            "from 'uhifadhi/widgets'",
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testTheAddWidgetPickerShowsEveryWidgetRenderedUnderItsGroup(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        $crawler = $client->request('GET', '/departments/widgets');

        $picker = $crawler->filter('[data-picker]');
        self::assertCount(1, $picker);
        // One entry per widget, each a REAL render on its own stage — never a
        // name in a list and never a schematic that could drift.
        self::assertCount(7, $picker->filter('.w-pickrow'));
        self::assertCount(7, $picker->filter('.w-pickstage .w-pickscale'));
        // A rail of the catalogue's groups, plus "All widgets", and a search
        // across all of them.
        self::assertCount(6, $picker->filter('[data-pick-tab]'));
        self::assertCount(1, $picker->filter('[data-pick-search]'));
        // The three already composed say so instead of offering Add.
        self::assertCount(3, $picker->filter('[data-pick-for]'));
        self::assertCount(4, $picker->filter('[data-pick-add]'));
    }

    public function testTheDesignsStripLeadsWithTheCompositionTheSurfaceShips(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        $crawler = $client->request('GET', '/departments/widgets');

        $cards = $crawler->filter('[data-presets="designs"] .w-preset');
        self::assertCount(6, $cards);
        self::assertSame(
            ['The departments board', 'Department cards', 'Team view', 'Configuration matrix', 'Org chart', 'Lens preview'],
            $cards->filter('.w-presetname')->each(static fn ($node): string => trim($node->text())),
        );
        // Exactly ONE card wears Active, anywhere on the page — the whole point
        // of the model.
        self::assertCount(1, $crawler->filter('.w-preset-active'));
        self::assertSame('default', $crawler->filter('.w-preset-active')->attr('data-preset-id'));
        // The card is a handle, not a picture: the count and the one action, and
        // no schematic to fall out of step with the layout.
        self::assertSame('3 widgets', trim($cards->first()->filter('.w-presetcount')->text()));
        self::assertCount(0, $crawler->filter('.w-presetmini'));
        // Selecting is client-side, so with no script the plain forms are offered
        // outright rather than pretending a card is a control.
        self::assertCount(1, $crawler->filter('[data-presets="designs"] noscript'));
    }

    public function testApplyingADesignLaysTheDashboardOutAsItAndMakesItActive(): void
    {
        $client = static::createClient();
        // Arranging your own dashboard is not a privilege: Staff may adopt a
        // design exactly as they may copy or reset one.
        $this->loginAs($client, TeamRoleEnum::Staff);

        $client->request('POST', '/departments/widgets/preset/c', ['_token' => $this->libraryToken($client)]);

        self::assertResponseRedirects('/departments');
        $crawler = $client->followRedirect();
        self::assertSame(
            ['matrix', 'kpis'],
            $crawler->filter('.w-grid > [data-widget-id]')->each(static fn ($node): string => (string) $node->attr('data-widget-id')),
            'the whole design, in its order',
        );

        $crawler = $client->request('GET', '/departments/widgets');
        self::assertSame('c', $crawler->filter('.w-preset-active')->attr('data-preset-id'));
    }

    public function testAnUnknownPresetIsUnprocessable(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        $client->request('POST', '/departments/widgets/preset/ghost', ['_token' => $this->libraryToken($client)]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testApplyingAPresetWithoutTheTokenIsRefused(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        $client->request('POST', '/departments/widgets/preset/a');

        self::assertResponseStatusCodeSame(403);
    }

    public function testMyPresetsAlwaysOffersTheEmptyCanvasAndNothingElseAtFirst(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        $crawler = $client->request('GET', '/departments/widgets');

        // One card, and it is the invitation: the way out of the empty state is
        // the same object as everything else in the strip.
        $cards = $crawler->filter('[data-presets="mine"] .w-preset');
        self::assertCount(1, $cards);
        self::assertSame('new', $cards->attr('data-preset-kind'));
        self::assertStringContainsString('+ New preset', trim($cards->filter('.w-presetname')->text()));
    }

    public function testCopyingABuiltInIsTheOneDoorIntoAnEditableLayout(): void
    {
        $client = static::createClient();
        // Staff, not Manager: a saved layout is your own, like every widget write.
        $this->loginAs($client, TeamRoleEnum::Staff);

        $crawler = $client->request('GET', '/departments/widgets');
        $client->submit($crawler->filter('.w-previewbar form[action$="/copy"]')->form());

        self::assertResponseRedirects('/departments/widgets');
        $crawler = $client->followRedirect();

        // The copy is theirs, it says whose design it came from, and it is ACTIVE
        // at once: you asked to customize the design you were on.
        $card = $crawler->filter('[data-presets="mine"] .w-preset[data-preset-kind="mine"]');
        self::assertCount(1, $card);
        self::assertSame('The departments board — copy', trim($card->filter('.w-presetname')->text()));
        self::assertSame($card->attr('data-preset-id'), $crawler->filter('.w-preset-active')->attr('data-preset-id'));
        // And now the canvas IS editable — that is the whole point of the copy.
        self::assertCount(3, $crawler->filter('.w-canvas ['.WidgetDom::GRIP.']'));
        self::assertCount(1, $crawler->filter('[data-picker-open]'));
    }

    public function testCopyingTwiceMakesASecondCardRatherThanReplacingTheFirst(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        foreach ([1, 2] as $ignored) {
            $client->request('POST', '/departments/widgets/preset/a/copy', ['_token' => $this->libraryToken($client)]);
            self::assertResponseRedirects('/departments/widgets');
        }

        $crawler = $client->request('GET', '/departments/widgets');
        self::assertSame(
            ['Department cards — copy', 'Department cards — copy 2'],
            $crawler->filter('[data-presets="mine"] .w-preset[data-preset-kind="mine"] .w-presetname')
                ->each(static fn ($node): string => trim($node->text())),
        );
    }

    public function testAComposedLayoutIsSavedUnderItsNameAndBecomesTheDashboard(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        // The "+ New preset" canvas was never on anyone's dashboard, so the whole
        // composition travels with the name.
        $client->request('POST', '/departments/widgets/presets', server: $this->jsonPost($client), content: (string) json_encode([
            'name' => '  Morning check  ',
            'order' => ['lanes', 'kpis'],
            'widgets' => ['lanes' => ['on' => true, 'cols' => 12], 'kpis' => ['on' => true, 'cols' => 6]],
        ]));

        // A preset write answers the way every form on the site does — a flash and
        // a redirect. The library's fetch() follows it and re-reads the page.
        self::assertResponseRedirects('/departments/widgets');

        $crawler = $client->request('GET', '/departments/widgets');
        $card = $crawler->filter('[data-presets="mine"] .w-preset[data-preset-kind="mine"]');
        self::assertCount(1, $card);
        self::assertSame('Morning check', trim($card->filter('.w-presetname')->text()), 'the name is trimmed');
        self::assertSame('2 widgets', trim($card->filter('.w-presetcount')->text()));
        // You composed the dashboard you wanted, so that is the dashboard you get.
        self::assertSame($card->attr('data-preset-id'), $crawler->filter('.w-preset-active')->attr('data-preset-id'));

        $crawler = $client->request('GET', '/departments');
        self::assertSame(
            ['lanes', 'kpis'],
            $crawler->filter('.w-grid > [data-widget-id]')->each(static fn ($node): string => (string) $node->attr('data-widget-id')),
        );
    }

    public function testEditingWhileACustomPresetIsActiveWritesThroughToIt(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);
        $uuid = $this->composePreset($client, 'Morning check', ['lens' => 12]);

        // The preset IS the layout, so there is nothing else to save.
        $client->request('POST', '/departments/widgets/save', server: $this->jsonPost($client), content: (string) json_encode([
            'order' => ['lens', 'matrix'],
            'widgets' => ['lens' => ['on' => true, 'cols' => 6], 'matrix' => ['on' => true, 'cols' => 12]],
        ]));
        self::assertResponseStatusCodeSame(204);

        $crawler = $client->request('GET', '/departments/widgets');
        $card = $crawler->filter('[data-presets="mine"] .w-preset[data-preset-id="'.$uuid.'"]');
        self::assertSame('2 widgets', trim($card->filter('.w-presetcount')->text()), 'the card follows the write');
        self::assertStringContainsString('w-preset-active', (string) $card->attr('class'), 'and it stays active');

        // Applying it again anywhere later brings back what was written through.
        $crawler = $client->request('GET', '/departments');
        self::assertSame(
            ['lens', 'matrix'],
            $crawler->filter('.w-grid > [data-widget-id]')->each(static fn ($node): string => (string) $node->attr('data-widget-id')),
        );
    }

    public function testApplyingASavedPresetPutsThatLayoutBackOn(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);
        $uuid = $this->composePreset($client, 'Morning check', ['lens' => 12]);

        // Wander off to one of the shipped designs …
        $client->request('POST', '/departments/widgets/preset/c', ['_token' => $this->libraryToken($client)]);

        // … then put the saved one back on.
        $client->request('POST', '/departments/widgets/presets/'.$uuid.'/apply', ['_token' => $this->libraryToken($client)]);

        self::assertResponseRedirects('/departments');
        $crawler = $client->followRedirect();
        self::assertSame(
            ['lens'],
            $crawler->filter('.w-grid > [data-widget-id]')->each(static fn ($node): string => (string) $node->attr('data-widget-id')),
        );
    }

    public function testAPresetCanBeRenamedAndDeleted(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);
        $this->composePreset($client, 'Morning check', ['lens' => 12]);

        $crawler = $client->request('GET', '/departments/widgets');
        $client->submit($crawler->filter('.w-previewbar form[action$="/rename"]')->form(['name' => 'Board meeting']));
        $crawler = $client->followRedirect();
        self::assertSame(
            'Board meeting',
            trim($crawler->filter('[data-presets="mine"] .w-preset[data-preset-kind="mine"] .w-presetname')->text()),
        );

        // Destructive, so it asks first — through the host's confirm modal.
        $delete = $crawler->filter('.w-previewbar form[action$="/delete"] button[type="submit"]');
        self::assertStringContainsString('confirm-modal', (string) $delete->attr('data-controller'));

        $client->submit($crawler->filter('.w-previewbar form[action$="/delete"]')->form());
        $crawler = $client->followRedirect();
        self::assertCount(0, $crawler->filter('[data-presets="mine"] .w-preset[data-preset-kind="mine"]'));
        // The dashboard cannot be left pointing at nothing: deleting the ACTIVE
        // one falls back to the design this surface ships with.
        self::assertSame('default', $crawler->filter('.w-preset-active')->attr('data-preset-id'));
    }

    public function testSavingUnderANameYouAlreadyUsedReplacesThatPreset(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);
        $this->composePreset($client, 'Morning check', ['lens' => 12]);
        $this->composePreset($client, 'Morning check', ['matrix' => 12]);

        $crawler = $client->request('GET', '/departments/widgets');

        // One card, not two of the same word — the newer capture wins.
        self::assertCount(1, $crawler->filter('[data-presets="mine"] .w-preset[data-preset-kind="mine"]'));
        $crawler = $client->request('GET', '/departments');
        self::assertSame(
            ['matrix'],
            $crawler->filter('.w-grid > [data-widget-id]')->each(static fn ($node): string => (string) $node->attr('data-widget-id')),
        );
    }

    public function testResettingGoesBackToTheDesignTheSurfaceShipsAndKeepsYourPresets(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);
        $this->composePreset($client, 'Morning check', ['lens' => 12]);

        $client->request('POST', '/departments/widgets/reset', server: [
            'HTTP_'.str_replace('-', '_', strtoupper(WidgetDom::CSRF_HEADER)) => $this->libraryToken($client),
        ]);

        self::assertResponseStatusCodeSame(204);
        $crawler = $client->request('GET', '/departments');
        self::assertCount(0, $crawler->filter('.w-grid > [data-widget-id="lens"]'), 'back to the shipped design');
        self::assertCount(1, $crawler->filter('.w-grid > [data-widget-id="kpis"]'));

        $crawler = $client->request('GET', '/departments/widgets');
        self::assertSame('default', $crawler->filter('.w-preset-active')->attr('data-preset-id'));
        self::assertCount(1, $crawler->filter('[data-presets="mine"] .w-preset[data-preset-kind="mine"]'), 'your own presets are kept');
    }

    public function testSomeoneElsesPresetIsNotFound(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);
        $uuid = $this->composePreset($client, 'Manager layout', ['lens' => 12]);

        // A different person, holding the URL: a preset that is not theirs reads
        // exactly like one that never existed — not 403, which would confirm it.
        $this->loginAs($client, TeamRoleEnum::Staff);

        foreach (['apply', 'delete'] as $write) {
            $client->request('POST', '/departments/widgets/presets/'.$uuid.'/'.$write, ['_token' => $this->libraryToken($client)]);
            self::assertResponseStatusCodeSame(404, $write.' on another person\'s preset');
        }

        $client->request('POST', '/departments/widgets/presets/'.$uuid.'/rename', ['_token' => $this->libraryToken($client), 'name' => 'Mine now']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnUnusableNameIsUnprocessableAndAPresetWriteNeedsTheToken(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        $client->request('POST', '/departments/widgets/presets', ['_token' => $this->libraryToken($client), 'name' => '   ']);
        self::assertResponseStatusCodeSame(422);

        $client->request('POST', '/departments/widgets/presets', ['name' => 'No token']);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/departments/widgets/preset/a/copy');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAWriteWithoutTheTokenIsRefused(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        $client->request('POST', '/departments/widgets/save', server: ['CONTENT_TYPE' => 'application/json'], content: '{"order":[],"widgets":{}}');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/departments/widgets/reset');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnUnknownWidgetIsUnprocessable(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);
        // On an editable preset, so the refusal is about the widget and not about
        // a built-in being immutable.
        $this->composePreset($client, 'Morning check', ['lens' => 12]);

        $client->request('POST', '/departments/widgets/save', server: $this->jsonPost($client), content: (string) json_encode([
            'order' => ['nope'],
            'widgets' => [],
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testAnonymousVisitorsAreSentToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/departments');
        self::assertResponseRedirects('http://localhost/login');

        $client->request('GET', '/departments/widgets');
        self::assertResponseRedirects('http://localhost/login');
    }

    public function testTheDashboardListsTheOrganisationsDepartments(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);
        DepartmentFactory::createOne(['name' => 'Protection & Security', 'modules' => [ModuleFactory::createOne(['name' => 'Patrols'])]]);

        $client->request('GET', '/departments');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1.pg', 'Departments');
    }

    /**
     * @return array<string, string>
     */
    private function jsonPost(KernelBrowser $client): array
    {
        return [
            'HTTP_'.str_replace('-', '_', strtoupper(WidgetDom::CSRF_HEADER)) => $this->libraryToken($client),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    /**
     * Every widget of the surface explicitly off — the base a test adds its own
     * "on" entries to, so a payload states a WHOLE layout the way the library's
     * script does and no widget rides along on its catalogue default.
     *
     * @return array<string, array{on: bool, cols: int}>
     */
    private static function allOff(): array
    {
        $off = [];
        foreach (DepartmentsWidgets::catalog()->ids() as $id) {
            $off[$id] = ['on' => false, 'cols' => 12];
        }

        return $off;
    }

    /**
     * COMPOSE a preset and save it, which is the "+ New preset" flow: the canvas
     * was never on anyone's dashboard, so the whole composition travels with the
     * name. It becomes the active preset, which is also what makes the canvas
     * editable. Returns the new preset's UUID — how every later write addresses
     * it.
     *
     * @param array<string, int> $layout widget id => span, in order; listed is on
     */
    private function composePreset(KernelBrowser $client, string $name, array $layout): string
    {
        $widgets = [];
        foreach ($layout as $id => $cols) {
            $widgets[$id] = ['on' => true, 'cols' => $cols];
        }

        $client->request('POST', '/departments/widgets/presets', server: $this->jsonPost($client), content: (string) json_encode([
            'name' => $name,
            'order' => array_keys($layout),
            'widgets' => $widgets,
        ]));
        self::assertResponseRedirects('/departments/widgets');

        $crawler = $client->request('GET', '/departments/widgets');

        return (string) $crawler->filter('.w-preset-active')->attr('data-preset-id');
    }

    /** The token the library page itself rendered — the same one the script would send. */
    private function libraryToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/departments/widgets');

        return (string) $crawler->filter('['.WidgetDom::ROOT.']')->attr(WidgetDom::CSRF_TOKEN);
    }
}
