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
        ], content: (string) json_encode(['order' => ['lens'], 'widgets' => ['lens' => ['on' => true, 'cols' => 12]]]));
        self::assertResponseStatusCodeSame(204);

        $client->request('POST', '/departments/widgets/reset', server: [
            'HTTP_'.str_replace('-', '_', strtoupper(WidgetDom::CSRF_HEADER)) => $token,
        ]);

        self::assertResponseStatusCodeSame(204);
        $crawler = $client->request('GET', '/departments');
        self::assertCount(0, $crawler->filter('.w-grid > [data-widget-id="lens"]'), 'back to the catalogue defaults');
        self::assertCount(1, $crawler->filter('.w-grid > [data-widget-id="kpis"]'));
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

    /** The token the library page itself rendered — the same one the script would send. */
    private function libraryToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/departments/widgets');

        return (string) $crawler->filter('['.WidgetDom::ROOT.']')->attr(WidgetDom::CSRF_TOKEN);
    }
}
