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

/**
 * The final brand mark (knockout "U" tile with the child tile seated in the cut
 * corner) on each host surface: sidebar, login, favicon. The parent silhouette
 * path is the contract — every surface must carry the exact ruled geometry,
 * never a redraw.
 */
final class BrandMarkTest extends AuthenticatedWebTestCase
{
    /** The parent tile with the U knocked out — the masterbrand outer path. */
    private const string KNOCKOUT = 'M18 0H82A18 18 0 0 1 100 18V64L64 100H18A18 18 0 0 1 0 82V18A18 18 0 0 1 18 0ZM23 20V50A27 27 0 0 0 77 50V20H62V50A12 12 0 0 1 38 50V20Z';

    public function testTheSidebarCarriesTheMasterbrandMarkAndWordmark(): void
    {
        $client = static::createClient();
        $this->loginAs($client);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.side .brand svg.brandmark');
        self::assertSelectorTextContains('.side .brand b', 'UHIFADHI');

        $svg = $crawler->filter('.side .brand svg.brandmark');
        self::assertSame(self::KNOCKOUT, $svg->filter('path')->first()->attr('d'));
        // Empty child tile seated in the cut corner — parent × 0.48 at (52,52).
        self::assertSame('translate(52,52) scale(0.48)', $svg->filter('g')->attr('transform'));
        self::assertCount(2, $svg->filter('g > path'));
        self::assertStringNotContainsString('<text', $svg->outerHtml());
    }

    public function testTheLoginPageCarriesTheMasterbrandMark(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.auth-brand svg.brandmark');
        self::assertSelectorTextContains('.auth-brand b', 'UHIFADHI');
        self::assertSame(self::KNOCKOUT, $crawler->filter('.auth-brand svg.brandmark path')->first()->attr('d'));
    }

    public function testTheFaviconIsTheSolidChipVariant(): void
    {
        // Below 32px the child tile drops its detail and becomes a solid chip —
        // the favicon ships that variant, never a shrunk letter mark. The mark
        // always renders in the theme's accent green: canvas jade by default,
        // the light theme's deep jade on a light scheme — never black.
        $icon = (string) file_get_contents(__DIR__.'/../../public/icon.svg');

        self::assertStringContainsString(self::KNOCKOUT, $icon);
        self::assertStringContainsString('translate(52,52) scale(0.48)', $icon);
        self::assertStringContainsString('.tile { fill: #3ED9A8; }', $icon);
        self::assertStringContainsString('prefers-color-scheme: light', $icon);
        self::assertStringContainsString('.tile { fill: #0F8A68; }', $icon);
        self::assertStringNotContainsString('#0C1310', $icon);
        self::assertStringNotContainsString('<text', $icon);
    }
}
