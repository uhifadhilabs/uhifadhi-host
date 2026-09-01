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
use Uhifadhi\Model\Widget;
use Uhifadhi\Model\WidgetDom;

/**
 * The seam between the PHP side of a widget library and assets/widgets.js.
 *
 * This exists because of a real bug in the patrols module: the controller
 * validated a CSRF token, the template rendered one — and the script never sent
 * it. Every server-side test passed, because each one builds its own header;
 * only a browser ever hit the 403. Nothing in a test that talks HTTP can catch
 * that, so these assertions read the shipped asset as TEXT and check that the
 * names on both sides of the seam are literally the same string.
 *
 * If a name here has to change, it changes in two places at once — which is the
 * point.
 */
final class WidgetLibraryAssetsTest extends TestCase
{
    private static function widgetsJs(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2).'/assets/widgets.js');
    }

    public function testTheScriptSendsTheCsrfHeaderThePhpSideReads(): void
    {
        $js = self::widgetsJs();

        self::assertStringContainsString(
            "export const CSRF_HEADER = '".WidgetDom::CSRF_HEADER."';",
            $js,
            'widgets.js must send the header WidgetDom declares.',
        );
        // Declaring it is not sending it: every write must actually attach it.
        self::assertStringContainsString('headers[CSRF_HEADER] = csrfToken();', $js);
        // Two CALL SITES, not counting the declaration: the optimistic save, and
        // commit(), which every other write (apply, copy, create, rename, delete,
        // reset) goes through. A write that built its own fetch() would be a
        // seventh way to forget the header — which is how a silent 403 shipped.
        self::assertSame(
            3,
            substr_count($js, 'postHeaders('),
            'Every write must go through postHeaders(): its declaration, the save, and commit() for the rest.',
        );
        self::assertSame(1, substr_count($js, 'function postHeaders('), 'one declaration, so the count above is two call sites');
        self::assertSame(
            2,
            substr_count($js, 'fetch('),
            'Only the optimistic save and commit() may talk to the server.',
        );
    }

    public function testTheScriptBuildsPresetUrlsFromTheTemplatesTheTemplateRenders(): void
    {
        $js = self::widgetsJs();

        // The component draws cards that did not exist when the page was
        // rendered, so it BUILDS their URLs from the route templates on the root
        // rather than reading an href off a card. The placeholder it substitutes
        // into is the one PHP puts there.
        self::assertStringContainsString('presetUrl: \''.WidgetDom::PRESET_URL."',", $js);
        self::assertStringContainsString('presetCopyUrl: \''.WidgetDom::PRESET_COPY_URL."',", $js);
        self::assertStringContainsString('template.replace(def.placeholder', $js);
    }

    public function testTheScriptReadsTheCatalogueAndClonesTheRenderedWidgets(): void
    {
        $js = self::widgetsJs();

        // Previewing a preset is a client-side RE-COMPOSITION over the catalogue
        // the page embedded — a preview that costs a round trip is a preview
        // nobody clicks twice.
        self::assertStringContainsString("catalog: '".WidgetDom::CATALOG."',", $js);
        self::assertStringContainsString('JSON.parse(catalogEl.textContent)', $js);
        // And the picture of a widget is the widget: cloned from the <template>
        // its own Twig partial rendered, never rebuilt in JavaScript.
        self::assertStringContainsString("template: '".WidgetDom::TEMPLATE."',", $js);
        self::assertStringContainsString('source.content.cloneNode(true)', $js);
    }

    public function testTheScriptNeverOffersAnEditWhileABuiltInIsActive(): void
    {
        $js = self::widgetsJs();

        // BUILT-INS ARE IMMUTABLE, and the component enforces it by not drawing
        // the chrome at all: `editable` is true only for one of the person's own
        // presets, or a new one being composed.
        self::assertStringContainsString("editable: 'new' === preview.kind,", $js);
        self::assertStringContainsString("editable: 'mine' === def.active.kind,", $js);
        // The one door out of a shipped design.
        self::assertStringContainsString('data-preset-copy', $js);
    }

    public function testTheScriptReadsTheTokenFromTheRootTheTemplateRenders(): void
    {
        $js = self::widgetsJs();

        self::assertStringContainsString("root: '".WidgetDom::ROOT."',", $js);
        self::assertStringContainsString("csrfToken: '".WidgetDom::CSRF_TOKEN."',", $js);
        // The token is read from the very element that carries the two URLs.
        self::assertStringContainsString('root.getAttribute(ATTR.csrfToken)', $js);
        self::assertStringContainsString("export const ROOT_SELECTOR = '[' + ATTR.root + ']';", $js);
    }

    public function testEveryAttributeOfTheContractIsUsedByTheScript(): void
    {
        $js = self::widgetsJs();

        foreach (WidgetDom::attributes() as $attribute) {
            self::assertStringContainsString(
                "'".$attribute."'",
                $js,
                \sprintf('widgets.js must use %s.', $attribute),
            );
        }
    }

    public function testTheScriptInventsNoAttributeTheContractDoesNotDeclare(): void
    {
        // The other direction of the same seam: a hook the script drives the page
        // through but PHP never declares is a hook no template will ever render.
        preg_match_all("/'(data-widget-[a-z-]+)'/", self::widgetsJs(), $matches);
        $used = array_values(array_unique($matches[1]));
        sort($used);
        $declared = WidgetDom::attributes();
        sort($declared);

        self::assertSame($declared, $used);
    }

    public function testResetAsksThroughTheHostsSharedConfirmModal(): void
    {
        $js = self::widgetsJs();

        // The library states WHAT to ask; the host's controller owns the dialog,
        // so every surface asks the same way. confirm() is not acceptable here.
        self::assertStringContainsString("'confirm-modal:confirmed'", $js);
        self::assertStringNotContainsString('window.confirm(', $js);
    }

    public function testTheSlideDurationMatchesTheStylesheetsTransition(): void
    {
        // The FLIP is split across two files: widgets.js writes the inverted
        // transform and app.css plays it back. A duration that drifts leaves the
        // cards mid-slide when the script strips the class.
        self::assertStringContainsString('const SLIDE_MS = 160;', self::widgetsJs());
        self::assertStringContainsString(
            '.w-sliding { transition: transform 160ms ease; }',
            (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/app.css'),
        );
    }

    public function testNoFrameworkClassSpellsATailwindUtility(): void
    {
        // A real defect, seen in the browser: the span classes were .w-12/.w-9/
        // .w-6/.w-3, which ARE Tailwind's width utilities on this host — the
        // utility layer out-cascaded the grid rule and a full-width widget
        // rendered 48px wide. The spans read .w-span-N now; this proves no `w-`
        // class in the framework block is a bare Tailwind scale name again.
        $css = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/app.css');
        // From the END of the block's banner (its own commentary names the
        // retired classes), then with the remaining comments stripped: rules only.
        $block = substr($css, (int) strpos($css, 'WIDGET FRAMEWORK'));
        $block = substr($block, (int) strpos($block, '*/'));
        $block = (string) preg_replace('#/\*.*?\*/#s', '', $block);

        preg_match_all('/\.(w-(?:\d+|full|auto|fit|min|max|px|screen|(?:\d?x?s|\d?x?l|sm|md|lg|xl)))\b/', $block, $matches);

        self::assertSame([], array_values(array_unique($matches[1])));
        self::assertStringContainsString('.w-grid > .w-cell.w-span-12 {', $block);
    }

    public function testEverySpanTheGridOffersHasARuleAndAChipLabel(): void
    {
        // THE SPAN VOCABULARY IS SPLIT ACROSS THREE FILES: the model says which
        // spans exist, app.css lays each of them out on the dashboard, in the
        // library and on the canvas, and widgets.js draws the width chip that
        // picks one. A span added to the model alone would be offered by the
        // chips and then laid out as a full row.
        $css = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/app.css');
        $js = self::widgetsJs();

        foreach (Widget::GRID_SPANS as $span) {
            self::assertStringContainsString(
                \sprintf('.w-grid > .w-cell.w-span-%d { grid-column: span %d; }', $span, $span),
                $css,
                \sprintf('The dashboard grid must lay out a span of %d.', $span),
            );
            // The library card's own base rule IS the full row, so only the
            // narrower spans get an attribute-scoped rule of their own.
            if (12 !== $span) {
                self::assertStringContainsString(
                    \sprintf('.w-card[data-widget-cols="%d"] { grid-column: span %d; }', $span, $span),
                    $css,
                    \sprintf('The library must lay a card out at a span of %d.', $span),
                );
            }
            self::assertMatchesRegularExpression(
                \sprintf('/SPAN_LABELS = \{[^}]*\b%d:/', $span),
                $js,
                \sprintf('The width chips must have a label for a span of %d.', $span),
            );
        }
    }

    public function testTheScriptIsExposedUnderItsBareSpecifier(): void
    {
        $importmap = require \dirname(__DIR__, 2).'/importmap.php';

        self::assertIsArray($importmap);
        self::assertSame(['path' => './assets/widgets.js'], $importmap['uhifadhi/widgets'] ?? null);
    }
}
