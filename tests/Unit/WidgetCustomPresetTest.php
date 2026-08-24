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
use Uhifadhi\Model\WidgetPreset;
use Uhifadhi\Service\WidgetService;

/**
 * A CUSTOM preset is the same idea as a shipped one with the authorship
 * reversed: a person arranges the dashboard, names the arrangement, and can put
 * it back on later. It is stored as the very same layout shape, so everything
 * downstream — the conversion, the validation, the strip — treats both alike.
 *
 * The pure half is here: capturing a layout, naming it, and surviving CATALOGUE
 * DRIFT. A preset saved last release may name a widget this release retired, or
 * miss one it gained; applying it must never be an error page.
 */
final class WidgetCustomPresetTest extends TestCase
{
    private static function catalog(): WidgetCatalog
    {
        return new WidgetCatalog(
            'demo',
            [new WidgetGroup('top', 'At a glance', 'The numbers first.')],
            [
                new Widget('kpis', 'KPI strip', 'top'),
                new Widget('map', 'Coverage map', 'top'),
                new Widget('chweek', 'Per week', 'top', cols: 6, spans: [9, 6, 3]),
                new Widget('cal', 'Calendar', 'top', on: false),
            ],
        );
    }

    public function testCapturingALayoutKeepsOnlyWhatIsOnInItsOrderAtItsWidth(): void
    {
        $catalog = self::catalog();
        $resolved = WidgetService::merge($catalog, [
            'order' => ['chweek', 'kpis', 'map'],
            'widgets' => [
                'chweek' => ['on' => true, 'cols' => 6],
                'kpis' => ['on' => true, 'cols' => 12],
                'map' => ['on' => false, 'cols' => 12],
            ],
        ]);

        // Absent IS off, so a captured layout lists the on widgets and nothing
        // else — exactly what a shipped preset lists.
        self::assertSame(['chweek' => 6, 'kpis' => 12], WidgetService::captureLayout($resolved));
    }

    public function testAnEmptyDashboardCannotBeSavedAsAPreset(): void
    {
        $this->expectException(InvalidWidgetPreferenceException::class);

        $catalog = self::catalog();
        $resolved = WidgetService::merge($catalog, [
            'order' => ['kpis'],
            'widgets' => array_map(
                static fn (string $id): array => ['on' => false, 'cols' => 12],
                array_combine($catalog->ids(), $catalog->ids()),
            ),
        ]);

        WidgetService::customPreset('uuid-ish', 'Nothing at all', WidgetService::captureLayout($resolved));
    }

    public function testAPresetNameIsTrimmedAndMustSaySomething(): void
    {
        self::assertSame('Morning check', WidgetService::presetName('  Morning check '));

        $this->expectException(InvalidWidgetPreferenceException::class);
        WidgetService::presetName('   ');
    }

    public function testAnOverlongPresetNameIsRefused(): void
    {
        $this->expectException(InvalidWidgetPreferenceException::class);

        WidgetService::presetName(str_repeat('a', 61));
    }

    public function testAPresetNamingARetiredWidgetStillApplies(): void
    {
        // Saved when the surface still shipped "weather"; that widget is gone.
        $preset = WidgetService::customPreset('mine', 'Morning check', ['weather' => 12, 'kpis' => 12]);

        $prefs = WidgetService::validate(self::catalog(), WidgetService::presetPayload(self::catalog(), $preset));

        // The ghost is dropped rather than throwing, and the widgets the surface
        // has GAINED since are appended, off — the same tolerance a stored
        // preference row already gets.
        self::assertSame(['kpis', 'map', 'chweek', 'cal'], $prefs['order']);
        self::assertTrue($prefs['widgets']['kpis']['on']);
        self::assertFalse($prefs['widgets']['map']['on']);
        self::assertFalse($prefs['widgets']['cal']['on']);
    }

    public function testAPresetWidthTheWidgetNoLongerOffersIsClampedNotRefused(): void
    {
        // chweek was full width once; it is a half-width chart now.
        $preset = WidgetService::customPreset('mine', 'Wide week', ['chweek' => 12]);

        $prefs = WidgetService::validate(self::catalog(), WidgetService::presetPayload(self::catalog(), $preset));

        self::assertSame(9, $prefs['widgets']['chweek']['cols'], 'clamped to the nearest span it does offer');
    }

    public function testACapturedLayoutRoundTripsThroughTheSavePath(): void
    {
        $catalog = self::catalog();
        $before = WidgetService::merge($catalog, [
            'order' => ['cal', 'chweek'],
            'widgets' => [
                'cal' => ['on' => true, 'cols' => 12],
                'chweek' => ['on' => true, 'cols' => 3],
                'kpis' => ['on' => false, 'cols' => 12],
                'map' => ['on' => false, 'cols' => 12],
            ],
        ]);

        $preset = WidgetService::customPreset('mine', 'Quiet week', WidgetService::captureLayout($before));
        $after = WidgetService::merge($catalog, WidgetService::validate($catalog, WidgetService::presetPayload($catalog, $preset)));

        $on = array_values(array_filter($after, static fn (array $widget): bool => $widget['on']));
        self::assertSame(['cal', 'chweek'], array_column($on, 'id'));
        self::assertSame([12, 3], array_column($on, 'cols'));
    }

    public function testACustomPresetIsJustAPreset(): void
    {
        // Same value object as a shipped one: the strip, the conversion and the
        // save path cannot tell them apart, which is why nothing downstream grew
        // a second code path.
        self::assertInstanceOf(WidgetPreset::class, WidgetService::customPreset('mine', 'Morning check', ['kpis' => 12]));
    }
}
