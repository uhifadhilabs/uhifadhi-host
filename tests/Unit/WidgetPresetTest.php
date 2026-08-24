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
 * A preset is "adopt this design wholesale": a named, described, COMPLETE layout
 * — which widgets are on, in what order, at what width. Like a catalogue it is
 * CODE, so a preset that names a widget the surface does not ship, or a width it
 * does not offer, throws where the container boots rather than storing a layout
 * nobody can render.
 *
 * The conversion goes through the ordinary save path on purpose: tolerant
 * ranking, span clamping and completeness stay in ONE place
 * ({@see WidgetService::validate()}), and a preset is just another way of
 * describing a layout to it.
 */
final class WidgetPresetTest extends TestCase
{
    /**
     * @param list<WidgetPreset> $presets
     */
    private static function catalog(array $presets = []): WidgetCatalog
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
                new Widget('cal', 'Calendar', 'detail', on: false),
            ],
            $presets,
        );
    }

    private static function preset(): WidgetPreset
    {
        return new WidgetPreset('numbers', 'Numbers first', 'The counts, then one chart.', [
            'kpis' => 12,
            'chweek' => 6,
        ]);
    }

    public function testAPresetIsANamedDescribedOrderedLayout(): void
    {
        $preset = self::preset();

        self::assertSame('numbers', $preset->id);
        self::assertSame('Numbers first', $preset->label);
        self::assertSame('The counts, then one chart.', $preset->description);
        // Listed IS on, and the listing order IS the dashboard order.
        self::assertSame(['kpis', 'chweek'], $preset->ids());
        self::assertSame(6, $preset->cols('chweek'));
        self::assertTrue($preset->shows('kpis'));
        self::assertFalse($preset->shows('log'));
    }

    public function testAPresetNeedsAnIdALabelAndAtLeastOneWidget(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new WidgetPreset('', 'Numbers first', 'x', ['kpis' => 12]);
    }

    public function testAnEmptyPresetIsRefused(): void
    {
        // "Everything off" is not a design direction, it is an empty screen.
        $this->expectException(\InvalidArgumentException::class);

        new WidgetPreset('empty', 'Empty', 'x', []);
    }

    public function testAWidthOutsideTheTwelveColumnGridIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new WidgetPreset('odd', 'Odd', 'x', ['kpis' => 5]);
    }

    public function testACatalogueRefusesAPresetNamingAWidgetItDoesNotShip(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"ghost"');

        self::catalog([new WidgetPreset('nope', 'Nope', 'x', ['ghost' => 12])]);
    }

    public function testACatalogueRefusesAPresetGivingAWidgetAWidthItDoesNotOffer(): void
    {
        // chweek is a half-width chart: it never offers the full row, so a preset
        // that hands it 12 is a design error, not something to clamp quietly.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('chweek');

        self::catalog([new WidgetPreset('wide', 'Wide', 'x', ['chweek' => 12])]);
    }

    public function testACatalogueRefusesTwoPresetsWithTheSameId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::catalog([
            new WidgetPreset('numbers', 'Numbers first', 'x', ['kpis' => 12]),
            new WidgetPreset('numbers', 'Numbers again', 'y', ['map' => 12]),
        ]);
    }

    public function testASurfaceMayShipNoPresetsAtAll(): void
    {
        // The strip is optional framework furniture: a surface that names no
        // design directions simply renders none.
        $catalog = self::catalog();

        self::assertSame([], $catalog->presets());
        self::assertNull($catalog->preset('numbers'));
    }

    public function testACatalogueReadsItsPresetsInDeclarationOrder(): void
    {
        $catalog = self::catalog([
            self::preset(),
            new WidgetPreset('log', 'Log first', 'The records, then the counts.', ['log' => 12, 'kpis' => 12]),
        ]);

        self::assertSame(['numbers', 'log'], array_column($catalog->presets(), 'id'));
        self::assertSame('Numbers first', $catalog->preset('numbers')?->label);
    }

    public function testAPresetBecomesACompleteStoredLayout(): void
    {
        $catalog = self::catalog([self::preset()]);

        $prefs = WidgetService::validate($catalog, WidgetService::presetPayload($catalog, self::preset()));

        // The preset's widgets lead, in its order; everything else follows, off.
        self::assertSame(['kpis', 'chweek', 'map', 'log', 'cal'], $prefs['order']);
        self::assertSame(
            ['kpis' => true, 'chweek' => true, 'map' => false, 'log' => false, 'cal' => false],
            array_map(static fn (array $widget): bool => $widget['on'], $prefs['widgets']),
        );
        self::assertSame(12, $prefs['widgets']['kpis']['cols']);
        self::assertSame(6, $prefs['widgets']['chweek']['cols']);
    }

    public function testResolvingThatStoredLayoutGivesTheDesignBack(): void
    {
        $catalog = self::catalog([self::preset()]);
        $stored = WidgetService::validate($catalog, WidgetService::presetPayload($catalog, self::preset()));

        $resolved = WidgetService::merge($catalog, $stored);
        $on = array_values(array_filter($resolved, static fn (array $widget): bool => $widget['on']));

        self::assertSame(['kpis', 'chweek'], array_column($on, 'id'));
        self::assertSame([12, 6], array_column($on, 'cols'));
    }

    public function testAnUnknownPresetIsARefusedPreference(): void
    {
        // Not an \InvalidArgumentException: the id arrives in a URL, so it is
        // untrusted input and the endpoint answers 422 rather than throwing 500.
        $this->expectException(InvalidWidgetPreferenceException::class);
        $this->expectExceptionMessage('"ghost"');

        WidgetService::presetOf(self::catalog([self::preset()]), 'ghost');
    }
}
