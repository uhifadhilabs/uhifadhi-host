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

namespace Uhifadhi\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Overview\AttentionItem;
use Uhifadhi\Overview\AttentionProviderInterface;
use Uhifadhi\Overview\AttentionSeverity;
use Uhifadhi\Overview\MapLayer;
use Uhifadhi\Overview\MapLayerProviderInterface;
use Uhifadhi\Overview\NowTile;
use Uhifadhi\Overview\NowTileProviderInterface;
use Uhifadhi\Overview\PulseEvent;
use Uhifadhi\Overview\PulseProviderInterface;

/**
 * WHAT EVERY WIDGET ON THE AREA OVERVIEW READS, BUILT ONCE.
 *
 * Three of the host's widgets and one of its plates render CONTRIBUTED PARTS
 * rather than lists the host wrote — the right-now strip, needs attention, the
 * pulse and the operational map's layers. This is where those parts are
 * collected, from every module the area has switched on, merged, and put in the
 * order the host's design says.
 *
 * ASKED ONCE PER RENDER, NEVER STORED. An attention item leaves the list when
 * the thing that raised it is dealt with, because nobody ever wrote it down; the
 * strip's numbers are the same numbers the duty board draws, because they are
 * literally the same objects.
 *
 * NOT INSTALLED HERE MEANS NOT ASKED. A provider whose module the area does not
 * have is skipped rather than called and discarded — a module that is off must
 * not be able to cost the page a query.
 */
final readonly class AreaOverviewComposer
{
    /**
     * The pulse's window. The design's own reading of the widget: what happened
     * while you were away, which for a morning shift is the evening before.
     */
    public const string PULSE_SINCE = '-18 hours';

    /**
     * @param iterable<NowTileProviderInterface>   $tileProviders
     * @param iterable<AttentionProviderInterface> $attentionProviders
     * @param iterable<MapLayerProviderInterface>  $layerProviders
     * @param iterable<PulseProviderInterface>     $pulseProviders
     */
    public function __construct(
        #[AutowireIterator(NowTileProviderInterface::TAG)]
        private iterable $tileProviders,
        #[AutowireIterator(AttentionProviderInterface::TAG)]
        private iterable $attentionProviders,
        #[AutowireIterator(MapLayerProviderInterface::TAG)]
        private iterable $layerProviders,
        #[AutowireIterator(PulseProviderInterface::TAG)]
        private iterable $pulseProviders,
    ) {
    }

    /**
     * @param list<string> $installedSlugs
     *
     * @return list<NowTile>
     */
    public function nowTiles(AreaOfInterest $area, array $installedSlugs, \DateTimeImmutable $now): array
    {
        $tiles = [];
        foreach ($this->installedOnly($this->tileProviders, $installedSlugs) as $provider) {
            foreach ($provider->nowTilesFor($area, $now) as $tile) {
                $tiles[] = $tile;
            }
        }

        // Stable: a tile keeps its provider's order among tiles of equal
        // priority, so the strip does not reshuffle itself between two renders
        // that measured the same thing.
        usort($tiles, static fn (NowTile $a, NowTile $b): int => $a->priority <=> $b->priority);

        return $tiles;
    }

    /**
     * SORTED BY URGENCY, NEVER BY MODULE — then oldest first inside a severity,
     * because within one level of "this is wrong" the thing that has been wrong
     * longest is the one nobody has looked at.
     *
     * @param list<string> $installedSlugs
     *
     * @return list<AttentionItem>
     */
    public function attention(AreaOfInterest $area, array $installedSlugs, \DateTimeImmutable $now): array
    {
        $items = [];
        foreach ($this->installedOnly($this->attentionProviders, $installedSlugs) as $provider) {
            foreach ($provider->attentionFor($area, $now) as $item) {
                $items[] = $item;
            }
        }

        usort($items, static fn (AttentionItem $a, AttentionItem $b): int => [$a->severity->rank(), -$a->ageSeconds] <=> [$b->severity->rank(), -$b->ageSeconds]);

        return $items;
    }

    /**
     * THE HOST'S OWN TILES, WHICH ARE SUMMARIES OF WHAT IT LAID OUT.
     *
     * "Needs attention" counts the list the host merged, so it cannot come
     * through {@see NowTileProviderInterface} without the host asking every
     * module twice. It is not an exception to the seam: the host is summarising
     * its own widget, not counting somebody else's records.
     *
     * The design's second host tile — "On duty", people on shift by station —
     * has no tile here, because the app records no roster and no handset
     * check-in. ABSENT IS NOT ZERO: a tile reading 0 would claim the host looked
     * and found nobody on duty.
     *
     * @param list<AttentionItem> $attention
     *
     * @return list<NowTile>
     */
    public function hostSummaryTiles(array $attention, string $url): array
    {
        if ([] === $attention) {
            return [];
        }

        $urgent = \count(array_filter($attention, static fn (AttentionItem $i): bool => AttentionSeverity::Now === $i->severity));
        $byModule = [];
        foreach ($attention as $item) {
            $byModule[$item->moduleLabel] = ($byModule[$item->moduleLabel] ?? 0) + 1;
        }
        $parts = [];
        foreach ($byModule as $label => $count) {
            $parts[] = \sprintf('%d %s', $count, mb_strtolower($label));
        }

        return [new NowTile(
            'AO·N1',
            AreaOverviewCatalogue::HOST_SLUG,
            'Needs attention',
            (string) \count($attention),
            implode(' · ', $parts),
            alarm: $urgent > 0 ? \sprintf('%d urgent', $urgent) : null,
            tone: $urgent > 0 ? NowTile::TONE_BAD : NowTile::TONE_PLAIN,
            url: $url,
            priority: 900,
        )];
    }

    /**
     * @param list<string> $installedSlugs
     *
     * @return list<MapLayer>
     */
    public function mapLayers(AreaOfInterest $area, array $installedSlugs, \DateTimeImmutable $now): array
    {
        $layers = [];
        foreach ($this->installedOnly($this->layerProviders, $installedSlugs) as $provider) {
            foreach ($provider->mapLayersFor($area, $now) as $layer) {
                $layers[] = $layer;
            }
        }

        return $layers;
    }

    /**
     * THE LEGEND, GROUPED BY THE MODULE THAT CONTRIBUTED THE LAYER. That grouping
     * is not cosmetic: it is the only way a person can tell why a layer vanished.
     *
     * @param list<MapLayer> $layers
     *
     * @return list<array{label: string, moduleSlug: string, layers: list<MapLayer>}>
     */
    public function legend(array $layers): array
    {
        $groups = [];
        foreach ($layers as $layer) {
            $groups[$layer->groupLabel] ??= ['label' => $layer->groupLabel, 'moduleSlug' => $layer->moduleSlug, 'layers' => []];
            $groups[$layer->groupLabel]['layers'][] = $layer;
        }

        return array_values($groups);
    }

    /**
     * The merged move log, newest first, grouped by the day it happened on.
     *
     * @param list<string> $installedSlugs
     *
     * @return list<array{day: \DateTimeImmutable, events: list<PulseEvent>}>
     */
    public function pulse(AreaOfInterest $area, array $installedSlugs, \DateTimeImmutable $now): array
    {
        $since = $now->modify(self::PULSE_SINCE);

        $events = [];
        foreach ($this->installedOnly($this->pulseProviders, $installedSlugs) as $provider) {
            foreach ($provider->pulseFor($area, $since, $now) as $event) {
                $events[] = $event;
            }
        }

        usort($events, static fn (PulseEvent $a, PulseEvent $b): int => $b->at <=> $a->at);

        $days = [];
        foreach ($events as $event) {
            $key = $event->at->format('Y-m-d');
            $days[$key] ??= ['day' => $event->at->setTime(0, 0), 'events' => []];
            $days[$key]['events'][] = $event;
        }

        return array_values($days);
    }

    /**
     * Only the providers whose module this area has switched on.
     *
     * @template T of NowTileProviderInterface|AttentionProviderInterface|MapLayerProviderInterface|PulseProviderInterface
     *
     * @param iterable<T>  $providers
     * @param list<string> $installedSlugs
     *
     * @return list<T>
     */
    private function installedOnly(iterable $providers, array $installedSlugs): array
    {
        $installed = [AreaOverviewCatalogue::HOST_SLUG, ...$installedSlugs];

        $kept = [];
        foreach ($providers as $provider) {
            if (\in_array($provider->moduleSlug(), $installed, true)) {
                $kept[] = $provider;
            }
        }

        return $kept;
    }
}
