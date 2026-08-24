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
        // Declaring it is not sending it: both writes must actually attach it.
        self::assertStringContainsString('headers[CSRF_HEADER] = csrfToken();', $js);
        // Two CALLS (the save and the reset), not counting the declaration.
        self::assertSame(
            2,
            substr_count($js, 'postHeaders({'),
            'Both the save and the reset POST must go through postHeaders().',
        );
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

    public function testTheScriptIsExposedUnderItsBareSpecifier(): void
    {
        $importmap = require \dirname(__DIR__, 2).'/importmap.php';

        self::assertIsArray($importmap);
        self::assertSame(['path' => './assets/widgets.js'], $importmap['uhifadhi/widgets'] ?? null);
    }
}
