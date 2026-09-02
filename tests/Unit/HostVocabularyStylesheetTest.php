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

namespace Uhifadhi\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * RULES OF THE HOST STYLESHEET THAT ONLY A BROWSER USED TO CATCH.
 *
 * The host's `assets/styles/app.css` is loaded on every page, INCLUDING the
 * pages a module bundle renders — so a rule written here lands on markup this
 * repository never sees. These defects came out of exactly that, and each is the
 * kind that no HTTP test can fail on because the server-side output is correct
 * and only the cascade is wrong:
 *
 *  1. A BARE ONE-WORD CLASS. `.who` is the team member cell — an avatar beside a
 *     name — and it was declared `display: flex` with no element in front of it.
 *     Modules use the same short word for a person's name INSIDE A SENTENCE, and
 *     a flexed span breaks the sentence in two. The fix belongs with the rule,
 *     not with each caller: the host's member cell is always a <div>, so the
 *     rule says so and a module's <span class="who"> is left alone.
 *
 *  2. A VOCABULARY LIVING TWICE. The docked map split was written in the
 *     incidents module, and the area overview draws the same split — so it
 *     cannot live in a module's stylesheet. Two copies loaded in either order
 *     render differently (the module's own second `.i-listcol` capped the column
 *     and re-enabled scrolling), which is precisely what the map-legend contract
 *     forbids: the same layer must render identically everywhere.
 *
 *  3. A STACKING CONTEXT THAT WAS NEVER OPENED. The platform map chrome — zoom ±,
 *     DIM, the base-layer menu, fullscreen — is a SIBLING of the Leaflet
 *     container, and Leaflet numbers its own panes from 200 (tiles) to 700
 *     (popups) with its controls at 800. A container that is merely positioned
 *     opens no stacking context, so those panes are not held inside it: they
 *     compete with the chrome directly and every one of them outranks it. The
 *     controls therefore painted for exactly as long as the tile pane was empty
 *     and vanished the moment the first tile arrived — on page load, before any
 *     poll, on every map in the product.
 *
 *     The fix is NOT a bigger number on the chrome. Raising it over Leaflet's
 *     800 would also raise it over the app's own modal layer (`.w-modal`, 200),
 *     which shares the `.page` stacking context — a map's zoom pill floating on
 *     top of a dialog is the next bug. The container isolates instead, which is
 *     what it should always have done: Leaflet's numbers are Leaflet's business
 *     and stop at its own edge.
 */
final class HostVocabularyStylesheetTest extends TestCase
{
    private static function css(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/app.css');
    }

    public function testTheMemberCellRuleNamesItsElementSoItCannotLandOnAModulesMarkup(): void
    {
        $css = self::css();

        self::assertStringContainsString('div.who { display: flex;', $css);
        // The bare selector is the bug. A rule that starts a line with `.who`
        // (rather than `div.who`, or a descendant of something) is one again.
        self::assertSame(
            0,
            preg_match_all('/^\.who\b/m', $css),
            'The host must not ship a bare .who rule: modules use the word for a name inside a sentence.',
        );
    }

    public function testTheDockedMapSplitIsTheHostsAndExistsOnce(): void
    {
        $css = self::css();

        // The split, the docked list and the hovered-pin card: the map plate's
        // own vocabulary, beside .viewer, which the host already owns.
        // The docked split, and the day-grouped feed the area pulse and the
        // incidents module's own feed both draw.
        foreach (['.i-split {', '.i-split > .i-mapcol {', '.i-listcol {', '.i-listhd {', '.i-listbody {', '.i-hit {', '.i-pincard {', '.i-day {', '.i-feed {'] as $rule) {
            // At the start of a line: the same selector indented inside a media
            // query is the same one copy, responding.
            self::assertSame(
                1,
                preg_match_all('/^'.preg_quote($rule, '/').'/m', $css),
                \sprintf('%s belongs to the host, exactly once — two copies drift.', $rule),
            );
        }
    }

    /**
     * The Leaflet container holds its own panes, so the chrome beside it survives
     * the first tile. Without this the controls render and then disappear.
     */
    public function testTheLeafletContainerIsolatesItsPanesSoTheChromeIsNotBuriedByThem(): void
    {
        $css = self::css();

        self::assertSame(
            1,
            preg_match('/^\.map-chrome-host \{[^}]*\bisolation:\s*isolate\b/m', $css),
            'The Leaflet container must open its own stacking context: its panes are numbered '
            .'200–800 and would otherwise paint over the chrome sitting beside them.',
        );
    }

    /**
     * And the fix stays an isolation, not a z-index arms race — the chrome must
     * remain below the app's own overlays.
     */
    public function testTheMapChromeStaysBelowTheAppsModalLayer(): void
    {
        $css = self::css();

        self::assertLessThan(
            self::zIndexOf($css, '.w-modal'),
            self::zIndexOf($css, '.map-chrome'),
            'A map control must never float over a dialog: isolate the container instead of '
            .'outbidding Leaflet with a bigger number.',
        );
    }

    /** The z-index a top-level rule declares. */
    private static function zIndexOf(string $css, string $selector): int
    {
        $pattern = '/^'.preg_quote($selector, '/').' \{[^}]*\bz-index:\s*(\d+)/m';
        if (1 !== preg_match($pattern, $css, $match)) {
            self::fail(\sprintf('%s must state its z-index for this rule to be checkable.', $selector));
        }

        return (int) $match[1];
    }

    /**
     * Every map that wears the platform chrome marks its Leaflet container, or
     * the isolation above (and the control/tooltip dressing that shares the
     * class) never reaches it.
     */
    public function testEveryMapThatMountsTheChromeMarksItsLeafletContainer(): void
    {
        $controllers = glob(\dirname(__DIR__, 2).'/assets/controllers/*.js') ?: [];
        self::assertNotEmpty($controllers);

        $mounting = [];
        foreach ($controllers as $file) {
            $source = (string) file_get_contents($file);
            if (!str_contains($source, 'mountMapChrome(')) {
                continue;
            }
            $mounting[] = basename($file);
            self::assertStringContainsString(
                "classList.add('map-chrome-host')",
                $source,
                \sprintf('%s mounts the platform chrome but never marks its Leaflet container.', basename($file)),
            );
        }

        self::assertNotEmpty($mounting, 'No controller mounts the platform map chrome — the sweep proved nothing.');
    }
}
