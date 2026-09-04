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

use Uhifadhi\Factory\AreaOfInterestFactory;

/**
 * THE SHELL PROMISES NOTHING IT CANNOT DO.
 *
 * A ranger reads the chrome as a statement of what the application can do, so
 * every row and every icon in it must lead somewhere real. This pins the rule
 * the field test made explicit: no inert row, no "coming soon" label, no status
 * light that is not reading anything, and no icon button whose click does
 * nothing. A surface that is not built yet is ABSENT from the chrome — it is
 * not present-but-dimmed.
 *
 * The counterpart is the positive claim in SidebarNavTest: the rows that ARE
 * here are the areas tree, Performance, Departments, Team and Files.
 */
final class ShellChromeHonestyTest extends AuthenticatedWebTestCase
{
    public function testEverySidebarRowIsARealLink(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        AreaOfInterestFactory::createOne(['name' => 'Ngorongoro']);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        // An inert row is the shell's way of drawing a surface that has no
        // route. There must not be one — the row is dropped instead.
        self::assertSelectorNotExists('.side .nav-item.off');
        self::assertStringNotContainsStringIgnoringCase(
            'coming soon',
            (string) $crawler->filter('.side')->html(),
        );
    }

    public function testTheAlertsRowIsGoneFromTheSystemSection(): void
    {
        $client = static::createClient();
        $this->loginAs($client);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertNotContains(
            'Alerts',
            $crawler->filter('.side .nav-item')->each(static fn ($node): string => trim($node->text())),
        );
    }

    public function testTheSidebarFooterCarriesNoUnbuiltSettingsRow(): void
    {
        $client = static::createClient();
        $this->loginAs($client);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.side .side-foot'));
    }

    /**
     * The topbar is the account cluster and the theme toggle, and nothing else:
     * the pulsing "worker" light read no worker, and the bell opened nothing.
     */
    public function testTheTopbarCarriesNoDeadFurniture(): void
    {
        $client = static::createClient();
        $this->loginAs($client);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $topbar = (string) $crawler->filter('.topbar')->html();
        self::assertStringNotContainsString('class="live"', $topbar);
        self::assertStringNotContainsStringIgnoringCase('worker', $topbar);
        self::assertStringNotContainsStringIgnoringCase('alert', $topbar);
        // What remains is real: the theme toggle, the signed-in viewer, sign out.
        self::assertSelectorExists('.topbar [data-action="theme#toggle"]');
        self::assertSelectorExists('.topbar .user');
        self::assertSelectorExists('.topbar a[href="/logout"]');
    }

    /**
     * The whole point of the trim: nothing in the chrome 404s or 500s. Every
     * href the sidebar draws is followed and must answer.
     */
    public function testEverySidebarLinkAnswers(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        AreaOfInterestFactory::createOne(['name' => 'Ngorongoro']);

        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $hrefs = array_unique($crawler->filter('.side a[href]')->each(
            static fn ($node): string => (string) $node->attr('href'),
        ));
        self::assertNotEmpty($hrefs);

        foreach ($hrefs as $href) {
            if (!str_starts_with($href, '/')) {
                continue;
            }
            $client->request('GET', $href);
            self::assertResponseIsSuccessful(\sprintf('Sidebar link %s must answer.', $href));
        }
    }
}
