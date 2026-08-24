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

    public function testTheLibraryDrawsEveryHeadedSectionAndEveryWidget(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        $crawler = $client->request('GET', '/departments/widgets');

        self::assertResponseIsSuccessful();
        self::assertCount(5, $crawler->filter('.w-section'));
        self::assertCount(7, $crawler->filter('.w-card'));

        $headings = $crawler->filter('.w-sectionhead')->each(static fn ($node): string => trim($node->text()));
        self::assertSame(
            ['Department cards', 'Team view', 'Configuration matrix', 'Org chart', 'Lens preview'],
            $headings,
        );
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
        self::assertNotSame('', (string) $root->attr(WidgetDom::CSRF_TOKEN));
        self::assertCount(1, $crawler->filter('['.WidgetDom::NOTICE.']'));
        self::assertCount(1, $crawler->filter('['.WidgetDom::RESET.']'));
        // Every card carries the whole per-widget contract the script drives.
        $card = $crawler->filter('.w-card[data-widget-id="matrix"]');
        self::assertSame('0', $card->attr(WidgetDom::ON), 'matrix is off by default');
        self::assertCount(1, $card->filter('['.WidgetDom::GRIP.']'));
        self::assertCount(1, $card->filter('['.WidgetDom::TOGGLE.']'));
        self::assertCount(1, $card->filter('['.WidgetDom::STATE.']'));
        self::assertCount(1, $card->filter('['.WidgetDom::PREVIEW.']'));
        // The width chips are exactly the spans the catalogue offers.
        self::assertCount(1, $card->filter('['.WidgetDom::SPAN.']'));
        self::assertCount(2, $crawler->filter('.w-card[data-widget-id="kpis"] ['.WidgetDom::SPAN.']'));
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

    public function testSavingALayoutPersistsItAndTheDashboardShowsIt(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        $client->request('POST', '/departments/widgets/save', server: $this->jsonPost($client), content: (string) json_encode([
            'order' => ['matrix', 'kpis'],
            'widgets' => [
                'matrix' => ['on' => true, 'cols' => 12],
                'kpis' => ['on' => true, 'cols' => 6],
                'cards' => ['on' => false, 'cols' => 12],
                'registry' => ['on' => false, 'cols' => 12],
            ],
        ]));

        self::assertResponseStatusCodeSame(204);

        $crawler = $client->request('GET', '/departments');
        $rendered = $crawler->filter('.w-grid > [data-widget-id]')->each(
            static fn ($node): string => (string) $node->attr('data-widget-id'),
        );
        self::assertSame(['matrix', 'kpis'], $rendered, 'the stored order and on/off win');
        self::assertStringContainsString('w-span-6', (string) $crawler->filter('.w-grid > [data-widget-id="kpis"]')->attr('class'));
    }

    public function testResettingThrowsTheLayoutAway(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        $token = $this->libraryToken($client);
        $client->request('POST', '/departments/widgets/save', server: [
            'HTTP_'.str_replace('-', '_', strtoupper(WidgetDom::CSRF_HEADER)) => $token,
            'CONTENT_TYPE' => 'application/json',
        ], content: (string) json_encode(['order' => ['lens'], 'widgets' => ['lens' => ['on' => true, 'cols' => 12]] + self::allOff()]));
        self::assertResponseStatusCodeSame(204);

        $client->request('POST', '/departments/widgets/reset', server: [
            'HTTP_'.str_replace('-', '_', strtoupper(WidgetDom::CSRF_HEADER)) => $token,
        ]);

        self::assertResponseStatusCodeSame(204);
        $crawler = $client->request('GET', '/departments');
        self::assertCount(0, $crawler->filter('.w-grid > [data-widget-id="lens"]'), 'back to the catalogue defaults');
        self::assertCount(1, $crawler->filter('.w-grid > [data-widget-id="kpis"]'));
    }

    public function testTheLibraryOffersEveryDesignAsAPreset(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        $crawler = $client->request('GET', '/departments/widgets');

        // One card per design direction, above the per-widget sections.
        $cards = $crawler->filter('[data-presets="designs"] .w-preset');
        self::assertCount(5, $cards);
        self::assertSame(
            ['Department cards', 'Team view', 'Configuration matrix', 'Org chart', 'Lens preview'],
            $cards->filter('.w-presetname')->each(static fn ($node): string => trim($node->text())),
        );
        // The schematic is CSS blocks, not an image and not script.
        self::assertCount(3, $crawler->filter('[data-presets="designs"] .w-preset[data-preset="a"] .w-presetcell'));
        // Applying is a plain form post carrying the library's own token, so it
        // works with JavaScript off …
        $form = $crawler->filter('[data-presets="designs"] .w-preset[data-preset="a"] form');
        self::assertSame('/departments/widgets/preset/a', $form->attr('action'));
        self::assertSame('post', strtolower((string) $form->attr('method')));
        self::assertNotSame('', (string) $form->filter('input[name="_token"]')->attr('value'));
        // … and asks first through the host's confirm modal when it is on.
        $apply = $crawler->filter('[data-presets="designs"] .w-preset[data-preset="a"] button[type="submit"]');
        self::assertStringContainsString('confirm-modal', (string) $apply->attr('data-controller'));
        self::assertStringContainsString(
            'overwrite',
            strtolower((string) $apply->attr('data-confirm-modal-message-value')),
            'the modal must say the current layout is replaced',
        );
    }

    public function testApplyingAPresetLaysTheDashboardOutAsThatDesign(): void
    {
        $client = static::createClient();
        // Arranging your own dashboard is not a privilege: Staff may adopt a
        // design exactly as they may save or reset one.
        $this->loginAs($client, TeamRoleEnum::Staff);

        $crawler = $client->request('GET', '/departments/widgets');
        $client->submit($crawler->filter('[data-presets="designs"] .w-preset[data-preset="c"] form')->form());

        self::assertResponseRedirects('/departments');
        $crawler = $client->followRedirect();

        $rendered = $crawler->filter('.w-grid > [data-widget-id]')->each(
            static fn ($node): string => (string) $node->attr('data-widget-id'),
        );
        self::assertSame(['matrix', 'kpis'], $rendered, 'the whole design, in its order');
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

    public function testMyPresetsSaysSoHonestlyWhenThereAreNone(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        $crawler = $client->request('GET', '/departments/widgets');

        self::assertCount(0, $crawler->filter('[data-presets="mine"] .w-preset'));
        self::assertStringContainsString('not saved a layout yet', $crawler->filter('.w-presetempty')->text());
        // The way out of the empty state is on the same screen.
        self::assertCount(1, $crawler->filter('form[action="/departments/widgets/presets"] input[name="name"]'));
    }

    public function testSavingTheCurrentLayoutAsAPresetCapturesExactlyWhatIsOnScreen(): void
    {
        $client = static::createClient();
        // Staff, not Manager: a saved layout is your own, like every widget write.
        $this->loginAs($client, TeamRoleEnum::Staff);

        $client->request('POST', '/departments/widgets/save', server: $this->jsonPost($client), content: (string) json_encode([
            'order' => ['lanes', 'kpis'],
            'widgets' => [
                'lanes' => ['on' => true, 'cols' => 12],
                'kpis' => ['on' => true, 'cols' => 6],
                'cards' => ['on' => false, 'cols' => 12],
                'registry' => ['on' => false, 'cols' => 12],
            ],
        ]));

        $crawler = $client->request('GET', '/departments/widgets');
        $client->submit($crawler->filter('form[action="/departments/widgets/presets"]')->form(['name' => '  Morning check  ']));

        self::assertResponseRedirects('/departments/widgets');
        $crawler = $client->followRedirect();

        $card = $crawler->filter('[data-presets="mine"] .w-preset');
        self::assertCount(1, $card);
        self::assertSame('Morning check', trim($card->filter('.w-presetname')->text()), 'the name is trimmed');
        // Two widgets are on, so the schematic has two blocks — one of them half width.
        $spans = $card->filter('.w-presetcell')->each(static fn ($node): string => (string) $node->attr('data-preset-span'));
        self::assertSame(['12', '6'], $spans);
    }

    public function testApplyingASavedPresetPutsThatLayoutBackOn(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);
        $this->savePreset($client, 'Morning check', ['order' => ['lens'], 'widgets' => ['lens' => ['on' => true, 'cols' => 12]] + self::allOff()]);

        // Wander off to a different layout …
        $client->request('POST', '/departments/widgets/save', server: $this->jsonPost($client), content: (string) json_encode([
            'order' => ['matrix'],
            'widgets' => ['matrix' => ['on' => true, 'cols' => 12]] + self::allOff(),
        ]));

        // … then put the saved one back on.
        $crawler = $client->request('GET', '/departments/widgets');
        $client->submit($crawler->filter('[data-presets="mine"] .w-preset form[action$="/apply"]')->form());

        self::assertResponseRedirects('/departments');
        $crawler = $client->followRedirect();
        $rendered = $crawler->filter('.w-grid > [data-widget-id]')->each(
            static fn ($node): string => (string) $node->attr('data-widget-id'),
        );
        self::assertSame(['lens'], $rendered);
    }

    public function testAPresetCanBeRenamedAndDeleted(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);
        $this->savePreset($client, 'Morning check', ['order' => ['lens'], 'widgets' => ['lens' => ['on' => true, 'cols' => 12]] + self::allOff()]);

        $crawler = $client->request('GET', '/departments/widgets');
        $client->submit($crawler->filter('form[action$="/rename"]')->form(['name' => 'Board meeting']));
        $crawler = $client->followRedirect();
        self::assertSame('Board meeting', trim($crawler->filter('[data-presets="mine"] .w-presetname')->text()));

        $client->submit($crawler->filter('form[action$="/delete"]')->form());
        $crawler = $client->followRedirect();
        self::assertCount(0, $crawler->filter('[data-presets="mine"] .w-preset'));
        // Deleting a preset never touches the dashboard it was taken from.
        $crawler = $client->request('GET', '/departments');
        self::assertCount(1, $crawler->filter('.w-grid > [data-widget-id="lens"]'));
    }

    public function testSavingUnderANameYouAlreadyUsedReplacesThatPreset(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);
        $this->savePreset($client, 'Morning check', ['order' => ['lens'], 'widgets' => ['lens' => ['on' => true, 'cols' => 12]] + self::allOff()]);
        $this->savePreset($client, 'Morning check', ['order' => ['matrix'], 'widgets' => ['matrix' => ['on' => true, 'cols' => 12]] + self::allOff()]);

        $crawler = $client->request('GET', '/departments/widgets');

        // One card, not two of the same word — the newer capture wins.
        self::assertCount(1, $crawler->filter('[data-presets="mine"] .w-preset'));
        $client->submit($crawler->filter('form[action$="/apply"]')->form());
        $crawler = $client->followRedirect();
        self::assertSame(
            ['matrix'],
            $crawler->filter('.w-grid > [data-widget-id]')->each(static fn ($node): string => (string) $node->attr('data-widget-id')),
        );
    }

    public function testSomeoneElsesPresetIsNotFound(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);
        $uuid = $this->savePreset($client, 'Manager layout', ['order' => ['lens'], 'widgets' => ['lens' => ['on' => true, 'cols' => 12]] + self::allOff()]);

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
     * Arrange the dashboard, then keep it under a name — the two steps a person
     * takes, in the order they take them. Returns the new preset's UUID, which is
     * how every later write addresses it.
     *
     * @param array<string, mixed> $layout
     */
    private function savePreset(KernelBrowser $client, string $name, array $layout): string
    {
        $client->request('POST', '/departments/widgets/save', server: $this->jsonPost($client), content: (string) json_encode($layout));
        self::assertResponseStatusCodeSame(204);

        $crawler = $client->request('GET', '/departments/widgets');
        $client->submit($crawler->filter('form[action="/departments/widgets/presets"]')->form(['name' => $name]));
        $crawler = $client->followRedirect();

        return (string) $crawler
            ->filter('[data-presets="mine"] .w-preset')->last()
            ->attr('data-preset');
    }

    /** The token the library page itself rendered — the same one the script would send. */
    private function libraryToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/departments/widgets');

        return (string) $crawler->filter('['.WidgetDom::ROOT.']')->attr(WidgetDom::CSRF_TOKEN);
    }
}
