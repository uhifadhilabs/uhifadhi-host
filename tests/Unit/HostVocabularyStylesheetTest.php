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
 * TWO RULES OF THE HOST STYLESHEET THAT ONLY A BROWSER USED TO CATCH.
 *
 * The host's `assets/styles/app.css` is loaded on every page, INCLUDING the
 * pages a module bundle renders — so a rule written here lands on markup this
 * repository never sees. Two defects came out of exactly that, and both are the
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
        foreach (['.i-split {', '.i-split > .i-mapcol {', '.i-listcol {', '.i-listhd {', '.i-listbody {', '.i-hit {', '.i-pincard {'] as $rule) {
            // At the start of a line: the same selector indented inside a media
            // query is the same one copy, responding.
            self::assertSame(
                1,
                preg_match_all('/^'.preg_quote($rule, '/').'/m', $css),
                \sprintf('%s belongs to the host, exactly once — two copies drift.', $rule),
            );
        }
    }
}
