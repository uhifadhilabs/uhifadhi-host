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

namespace Uhifadhi\Tests\Unit\Overview;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Overview\AttentionItem;
use Uhifadhi\Overview\AttentionProviderInterface;
use Uhifadhi\Overview\AttentionSeverity;
use Uhifadhi\Overview\MapLayer;
use Uhifadhi\Overview\MapLayerProviderInterface;
use Uhifadhi\Overview\NowTile;
use Uhifadhi\Overview\NowTileProviderInterface;
use Uhifadhi\Service\AreaOverviewComposer;

/**
 * THE HOST OWNS THE LIST; A MODULE OWNS THE ITEM — proven on the merge.
 *
 * The right-now strip, needs attention and the map's legend are the three places
 * the seam is visible in the product, and all three are assembled here. What
 * these assertions pin is the part a comment cannot: that the host sorts by
 * urgency and never by module, that a module the area has not installed is not
 * even asked, and that the legend's grouping survives the merge.
 */
final class AreaOverviewComposerTest extends TestCase
{
    private static function item(string $module, AttentionSeverity $severity, int $ageSeconds): AttentionItem
    {
        return new AttentionItem(
            $severity,
            $module,
            ucfirst($module),
            \sprintf('Something from %s, %d seconds old.', $module, $ageSeconds),
            'a kind of thing',
            \sprintf('%d s', $ageSeconds),
            $ageSeconds,
            '/somewhere',
        );
    }

    /**
     * @param list<AttentionItem> $items
     */
    private static function attentionProvider(string $module, array $items): AttentionProviderInterface
    {
        return new class($module, $items) implements AttentionProviderInterface {
            /** @param list<AttentionItem> $items */
            public function __construct(private string $module, private array $items)
            {
            }

            public function moduleSlug(): string
            {
                return $this->module;
            }

            public function attentionFor(AreaOfInterest $area, \DateTimeImmutable $now): array
            {
                return $this->items;
            }
        };
    }

    /**
     * @param list<NowTile> $tiles
     */
    private static function tileProvider(string $module, array $tiles): NowTileProviderInterface
    {
        return new class($module, $tiles) implements NowTileProviderInterface {
            /** @param list<NowTile> $tiles */
            public function __construct(private string $module, private array $tiles)
            {
            }

            public function moduleSlug(): string
            {
                return $this->module;
            }

            public function nowTilesFor(AreaOfInterest $area, \DateTimeImmutable $now): array
            {
                return $this->tiles;
            }
        };
    }

    /**
     * @param list<MapLayer> $layers
     */
    private static function layerProvider(string $module, array $layers): MapLayerProviderInterface
    {
        return new class($module, $layers) implements MapLayerProviderInterface {
            /** @param list<MapLayer> $layers */
            public function __construct(private string $module, private array $layers)
            {
            }

            public function moduleSlug(): string
            {
                return $this->module;
            }

            public function mapLayersFor(AreaOfInterest $area, \DateTimeImmutable $now): array
            {
                return $this->layers;
            }
        };
    }

    /**
     * @param list<NowTileProviderInterface>   $tileProviders
     * @param list<AttentionProviderInterface> $attentionProviders
     * @param list<MapLayerProviderInterface>  $layerProviders
     */
    private static function composer(
        array $tileProviders = [],
        array $attentionProviders = [],
        array $layerProviders = [],
    ): AreaOverviewComposer {
        return new AreaOverviewComposer($tileProviders, $attentionProviders, $layerProviders, []);
    }

    public function testTheListIsSortedByUrgencyAndNeverByModule(): void
    {
        $composer = self::composer(attentionProviders: [
            self::attentionProvider('patrols', [
                self::item('patrols', AttentionSeverity::Watch, 99),
                self::item('patrols', AttentionSeverity::Now, 100),
            ]),
            self::attentionProvider('incidents', [
                self::item('incidents', AttentionSeverity::Soon, 10),
                self::item('incidents', AttentionSeverity::Now, 500),
            ]),
        ]);

        $items = $composer->attention(new AreaOfInterest(), ['patrols', 'incidents'], new \DateTimeImmutable());

        // Two urgent things first, the older of them first, and only then the
        // module that happened to be asked first.
        self::assertSame(
            [['incidents', 500], ['patrols', 100], ['incidents', 10], ['patrols', 99]],
            array_map(static fn (AttentionItem $i): array => [$i->moduleSlug, $i->ageSeconds], $items),
        );
    }

    public function testAModuleTheAreaDoesNotHaveIsNeverEvenAsked(): void
    {
        $composer = self::composer(attentionProviders: [
            self::attentionProvider('patrols', [self::item('patrols', AttentionSeverity::Now, 1)]),
            self::attentionProvider('permits', [self::item('permits', AttentionSeverity::Now, 2)]),
        ]);

        $items = $composer->attention(new AreaOfInterest(), ['patrols'], new \DateTimeImmutable());

        self::assertSame(['patrols'], array_column($items, 'moduleSlug'));
    }

    public function testTheHostsOwnTileCountsTheListItLaidOutAndSaysNothingOnAGoodDay(): void
    {
        $composer = self::composer(attentionProviders: [
            self::attentionProvider('patrols', [
                self::item('patrols', AttentionSeverity::Now, 1),
                self::item('patrols', AttentionSeverity::Soon, 2),
            ]),
            self::attentionProvider('incidents', [self::item('incidents', AttentionSeverity::Watch, 3)]),
        ]);
        $items = $composer->attention(new AreaOfInterest(), ['patrols', 'incidents'], new \DateTimeImmutable());

        $tiles = $composer->hostSummaryTiles($items, '/areas/x');

        self::assertCount(1, $tiles);
        self::assertSame('3', $tiles[0]->value);
        self::assertSame('1 urgent', $tiles[0]->alarm);
        self::assertSame('2 patrols · 1 incidents', $tiles[0]->subline);
        self::assertSame(NowTile::TONE_BAD, $tiles[0]->tone);

        // ABSENT IS NOT ZERO: a quiet morning gets no tile, not a tile reading 0.
        self::assertSame([], $composer->hostSummaryTiles([], '/areas/x'));
    }

    public function testTheStripIsOrderedByPriorityAndAModuleThatHasNothingToSayAddsNoTile(): void
    {
        $composer = self::composer(tileProviders: [
            self::tileProvider('incidents', [new NowTile('IN·N1', 'incidents', 'Open incidents', '31', priority: 300)]),
            self::tileProvider('patrols', [new NowTile('PL·N1', 'patrols', 'Patrols out', '3', priority: 100)]),
            self::tileProvider('permits', []),
        ]);

        $tiles = $composer->nowTiles(new AreaOfInterest(), ['patrols', 'incidents', 'permits'], new \DateTimeImmutable());

        self::assertSame(['PL·N1', 'IN·N1'], array_column($tiles, 'index'));
    }

    public function testTheLegendIsGroupedByTheModuleThatContributedTheLayer(): void
    {
        $empty = ['type' => 'FeatureCollection', 'features' => []];
        $composer = self::composer(layerProviders: [
            self::layerProvider('host', [new MapLayer('host.boundary', 'host', 'The area', 'Boundary', '#49E6B4', $empty, MapLayer::STYLE_LINE)]),
            self::layerProvider('patrols', [
                new MapLayer('patrols.live', 'patrols', 'Patrols', 'Out right now', '#3ED9A8', $empty, MapLayer::STYLE_LINE),
                new MapLayer('patrols.done', 'patrols', 'Patrols', 'Closed today', '#B9C8BD', $empty, MapLayer::STYLE_LINE),
            ]),
        ]);

        $legend = $composer->legend($composer->mapLayers(new AreaOfInterest(), ['patrols'], new \DateTimeImmutable()));

        self::assertSame(['The area', 'Patrols'], array_column($legend, 'label'));
        self::assertCount(2, $legend[1]['layers']);
    }
}
