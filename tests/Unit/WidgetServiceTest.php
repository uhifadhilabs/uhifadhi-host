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
use Uhifadhi\Exception\InvalidWidgetPreferenceException;
use Uhifadhi\Model\Widget;
use Uhifadhi\Model\WidgetCatalog;
use Uhifadhi\Model\WidgetGroup;
use Uhifadhi\Service\WidgetService;

/**
 * The pure preference algebra: a surface's catalogue defaults, a stored row
 * merged over them, and the validation that decides what may be stored at all.
 * Persistence is exercised by the integration tests.
 *
 * These cases are the patrol module's battle-tested suite, generalised: the
 * catalogue is now the surface's, not a constant, and every widget carries the
 * group the library files it under.
 */
final class WidgetServiceTest extends TestCase
{
    /** A stand-in surface with the same shape patrol has: full-width widgets and two half-width charts. */
    private static function catalog(): WidgetCatalog
    {
        return new WidgetCatalog(
            'demo',
            [
                new WidgetGroup('top', 'At a glance', 'The numbers first.'),
                new WidgetGroup('detail', 'In detail', 'The records behind them.'),
            ],
            [
                new Widget('kpis', 'KPI strip', 'top'),
                new Widget('map', 'Coverage map', 'top'),
                new Widget('log', 'Log', 'detail'),
                new Widget('chweek', 'Per week', 'detail', cols: 6, spans: [9, 6, 3]),
                new Widget('chstation', 'By station', 'detail', cols: 6, spans: [9, 6, 3]),
                new Widget('cal', 'Calendar', 'detail', on: false),
            ],
        );
    }

    public function testTheDefaultsAreTheCataloguesOwnLayout(): void
    {
        $widgets = WidgetService::merge(self::catalog(), null);

        self::assertSame(['kpis', 'map', 'log', 'chweek', 'chstation', 'cal'], array_column($widgets, 'id'));
        self::assertSame([12, 12, 12, 6, 6, 12], array_column($widgets, 'cols'));
        // A fresh user sees every widget the catalogue ships switched on — except
        // the ones it deliberately ships off.
        self::assertSame([true, true, true, true, true, false], array_column($widgets, 'on'));
        self::assertSame('KPI strip', $widgets[0]['label']);
    }

    public function testEveryResolvedWidgetCarriesTheGroupTheLibraryFilesItUnder(): void
    {
        $byId = array_column(WidgetService::merge(self::catalog(), null), null, 'id');

        self::assertSame('top', $byId['kpis']['group']);
        self::assertSame('detail', $byId['cal']['group']);
    }

    public function testOnlyTheWidgetsThatDeclareItOfferFullWidth(): void
    {
        $spans = array_column(WidgetService::merge(self::catalog(), null), 'spans', 'id');

        // The grid's whole vocabulary, because neither declares a subset.
        self::assertSame([12, 9, 6, 4, 3], $spans['map']);
        self::assertSame([12, 9, 6, 4, 3], $spans['cal']);
        self::assertSame([9, 6, 3], $spans['chweek']);
    }

    public function testStoredPreferencesWinOverTheDefaults(): void
    {
        $widgets = WidgetService::merge(self::catalog(), [
            'order' => ['cal', 'kpis'],
            'widgets' => [
                'cal' => ['on' => true, 'cols' => 6],
                'map' => ['on' => false, 'cols' => 12],
            ],
        ]);

        // Stored order first, then whatever the stored order never mentioned, in
        // catalogue order — a widget added by a later release still appears.
        self::assertSame(['cal', 'kpis', 'map', 'log', 'chweek', 'chstation'], array_column($widgets, 'id'));
        $byId = array_column($widgets, null, 'id');
        self::assertSame(6, $byId['cal']['cols']);
        self::assertFalse($byId['map']['on']);
        // Untouched widgets keep the catalogue default.
        self::assertSame(12, $byId['log']['cols']);
        self::assertTrue($byId['log']['on']);
    }

    public function testAStoredSpanOutsideTheAllowedSetIsClampedOnTheWayOut(): void
    {
        // A row written before a widget lost full width must not resurrect it.
        $byId = array_column(WidgetService::merge(self::catalog(), [
            'order' => [],
            'widgets' => ['chweek' => ['on' => true, 'cols' => 12]],
        ]), null, 'id');

        self::assertSame(9, $byId['chweek']['cols']);
    }

    public function testValidationCanonicalisesAPayload(): void
    {
        $prefs = WidgetService::validate(self::catalog(), [
            'order' => ['cal', 'map'],
            'widgets' => [
                'cal' => ['on' => false, 'cols' => 6],
                'map' => ['on' => true, 'cols' => 9],
            ],
        ]);

        self::assertSame(['cal', 'map', 'kpis', 'log', 'chweek', 'chstation'], $prefs['order']);
        self::assertSame(['on' => false, 'cols' => 6], $prefs['widgets']['cal']);
        self::assertSame(['on' => true, 'cols' => 9], $prefs['widgets']['map']);
        // Widgets the payload never named are stored at their defaults, so the
        // stored row is always a complete picture.
        self::assertSame(['on' => true, 'cols' => 6], $prefs['widgets']['chstation']);
    }

    public function testAWidgetTheCatalogueShipsOffStaysOffWhenThePayloadIsSilentAboutIt(): void
    {
        $prefs = WidgetService::validate(self::catalog(), ['order' => [], 'widgets' => []]);

        self::assertSame(['on' => false, 'cols' => 12], $prefs['widgets']['cal']);
        self::assertSame(['on' => true, 'cols' => 12], $prefs['widgets']['kpis']);
    }

    public function testValidationClampsASpanToTheNearestAllowedOne(): void
    {
        $prefs = WidgetService::validate(self::catalog(), [
            'order' => [],
            'widgets' => [
                'chweek' => ['on' => true, 'cols' => 12],
                'map' => ['on' => true, 'cols' => 7],
                'log' => ['on' => true, 'cols' => 400],
            ],
        ]);

        self::assertSame(9, $prefs['widgets']['chweek']['cols']);
        self::assertSame(6, $prefs['widgets']['map']['cols']);
        self::assertSame(12, $prefs['widgets']['log']['cols']);
    }

    public function testAPartialOrderRanksTheUnlistedWidgetsAfterTheListedOnes(): void
    {
        // The posted layout is never trusted to be complete: a stale page, or a
        // release that added a widget, posts only what it knows about.
        $prefs = WidgetService::validate(self::catalog(), [
            'order' => ['cal', 'chstation'],
            'widgets' => ['cal' => ['on' => true, 'cols' => 12]],
        ]);

        self::assertSame(['cal', 'chstation', 'kpis', 'map', 'log', 'chweek'], $prefs['order']);
        // Every catalogue widget is stored, so the row is a complete picture.
        $stored = array_keys($prefs['widgets']);
        $catalogue = self::catalog()->ids();
        sort($stored);
        sort($catalogue);
        self::assertSame($catalogue, $stored);
        // A widget the payload never named keeps its catalogue default.
        self::assertSame(['on' => true, 'cols' => 12], $prefs['widgets']['map']);
    }

    public function testARepeatedWidgetIdIsRankedOnce(): void
    {
        $prefs = WidgetService::validate(self::catalog(), ['order' => ['cal', 'cal', 'map'], 'widgets' => []]);

        self::assertSame(['cal', 'map', 'kpis', 'log', 'chweek', 'chstation'], $prefs['order']);
    }

    public function testAStoredOrderMissingAWidgetStillResolvesIt(): void
    {
        // The same tolerance on the way out: a row written before a widget existed
        // must still render that widget, ranked after the ones it does list.
        $widgets = WidgetService::merge(self::catalog(), [
            'order' => ['cal', 'map'],
            'widgets' => ['cal' => ['on' => true, 'cols' => 12]],
        ]);

        self::assertSame(['cal', 'map', 'kpis', 'log', 'chweek', 'chstation'], array_column($widgets, 'id'));
    }

    public function testAnUnknownWidgetIdIsRejected(): void
    {
        $this->expectException(InvalidWidgetPreferenceException::class);

        WidgetService::validate(self::catalog(), [
            'order' => [],
            'widgets' => ['tracker' => ['on' => true, 'cols' => 6]],
        ]);
    }

    public function testAnUnknownWidgetIdInTheOrderIsRejected(): void
    {
        $this->expectException(InvalidWidgetPreferenceException::class);

        WidgetService::validate(self::catalog(), ['order' => ['tracker'], 'widgets' => []]);
    }

    public function testAMalformedPayloadIsRejected(): void
    {
        $this->expectException(InvalidWidgetPreferenceException::class);

        WidgetService::validate(self::catalog(), ['order' => 'kpis', 'widgets' => []]);
    }

    public function testAMalformedWidgetEntryIsRejected(): void
    {
        $this->expectException(InvalidWidgetPreferenceException::class);

        WidgetService::validate(self::catalog(), ['order' => [], 'widgets' => ['map' => 'on']]);
    }

    public function testAStoredRowThatWentBadFallsBackToTheDefaults(): void
    {
        // merge() never throws: a hand-edited or half-written row must not take
        // the dashboard down, it just stops being honoured.
        self::assertSame(
            WidgetService::merge(self::catalog(), null),
            WidgetService::merge(self::catalog(), ['order' => 'nonsense', 'widgets' => 42]),
        );
    }

    public function testAWidgetIdFromAnotherSurfaceIsNotHonoured(): void
    {
        // Two surfaces may both ship a "map"; a row is scoped to its surface, so a
        // stored id the THIS catalogue does not ship is simply dropped on read.
        $widgets = WidgetService::merge(self::catalog(), [
            'order' => ['patrol_only', 'cal'],
            'widgets' => ['patrol_only' => ['on' => true, 'cols' => 3]],
        ]);

        self::assertSame(['cal', 'kpis', 'map', 'log', 'chweek', 'chstation'], array_column($widgets, 'id'));
    }

    /* ---- a composition is not a preference ------------------------------- */

    public function testAComposedLayoutHoldsExactlyWhatTheCanvasStatedAndNothingElse(): void
    {
        // THE CANVAS IS THE DASHBOARD: it holds exactly the composition, so a
        // widget it does not name is OFF. merge() would fill one in with the
        // CATALOGUE's answer — right for a stored preference, which must be a
        // complete picture, and wrong here: it would put widgets on a preset
        // nobody added.
        $catalog = self::catalog();

        $layout = WidgetService::composedLayout($catalog, [
            'order' => ['map', 'kpis'],
            'widgets' => ['map' => ['on' => true, 'cols' => 12], 'kpis' => ['on' => true, 'cols' => 12]],
        ]);

        self::assertSame(['map' => 12, 'kpis' => 12], $layout, 'the composition, in its own order');
    }

    public function testAComposedLayoutClampsASpanTheCatalogueNoLongerOffers(): void
    {
        $catalog = self::catalog();

        $layout = WidgetService::composedLayout($catalog, [
            'order' => ['chweek'],
            'widgets' => ['chweek' => ['on' => true, 'cols' => 12]],
        ]);

        // "chweek" offers [9, 6, 3]; 12 is clamped to the nearest it does offer
        // rather than refused — a saved composition must survive the catalogue
        // narrowing a widget.
        self::assertSame(['chweek' => 9], $layout);
    }

    public function testAComposedLayoutDropsWhatTheCanvasSwitchedOffAndKeepsAnUnorderedOn(): void
    {
        $catalog = self::catalog();

        $layout = WidgetService::composedLayout($catalog, [
            'order' => ['kpis', 'map'],
            // Explicitly off beats being listed …
            'widgets' => ['map' => ['on' => false, 'cols' => 12], 'cal' => ['on' => true, 'cols' => 12]],
        ]);

        // … and explicitly on beats being left out of the order: a caller that
        // said `on` and forgot to list it meant to include it.
        self::assertSame(['kpis' => 12, 'cal' => 12], $layout);
    }

    public function testAComposedLayoutStillRefusesAWidgetTheSurfaceDoesNotShip(): void
    {
        $this->expectException(InvalidWidgetPreferenceException::class);

        WidgetService::composedLayout(self::catalog(), ['order' => ['ghost'], 'widgets' => []]);
    }

    public function testASavedLayoutSaysWhatItHoldsInTheOneSentenceBothSidesWrite(): void
    {
        // assets/widgets.js writes this same line for a card it draws after a
        // save, so the two must match word for word.
        self::assertSame('1 widget, in your order and at your widths.', WidgetService::countLine(['kpis' => 12]));
        self::assertSame('2 widgets, in your order and at your widths.', WidgetService::countLine(['kpis' => 12, 'map' => 12]));
    }
}
