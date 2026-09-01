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
use Uhifadhi\Model\AreaOverviewPresets;
use Uhifadhi\Model\WidgetCatalog;
use Uhifadhi\Model\WidgetPreset;
use Uhifadhi\Overview\ContributesStylesheetInterface;
use Uhifadhi\Overview\OverviewContributorInterface;

/**
 * THE AREA OVERVIEW'S CATALOGUE, ASSEMBLED PER AREA.
 *
 * Every other surface in the product declares its catalogue statically, because
 * one owner wrote every widget on it. This one cannot: `/areas/{uuid}` is
 * COMPOSED, and which widgets exist depends on which modules the area has
 * switched on. So the catalogue is built at request time from the tagged
 * {@see OverviewContributorInterface}s, filtered to this area's modules.
 *
 * THE ORDER OF THE HEADED SECTIONS IS PROVENANCE: the host first, then each
 * installed module in the order the area lists them, then the catalogue's
 * uninstalled ones last. That is the whole contributor reading — it is why a
 * person can tell that "Out right now" came from Patrols and that its
 * disappearance is the system working.
 *
 * PRESETS ARE FILTERED, NOT VALIDATED. {@see AreaOverviewPresets} declares five
 * layouts and four of them name a module's widget, because four of them are
 * convictions about operational work. A layout is trimmed to the widgets this
 * area actually has before it reaches {@see WidgetCatalog}, which would
 * otherwise (correctly) refuse to boot. A preset with nothing left is dropped:
 * "everything off" is not a design, and a card promising a layout that renders
 * an empty page is worse than no card.
 */
final readonly class AreaOverviewCatalogue
{
    /** What a stored {@see \Uhifadhi\Entity\WidgetPreference} row is keyed by. */
    public const string SURFACE = 'area-overview';

    /** The slug the host's own widgets answer to. */
    public const string HOST_SLUG = 'host';

    /** The slug the honest not-installed-here section answers to. */
    public const string SEAM_SLUG = 'next';

    /**
     * @param iterable<OverviewContributorInterface> $contributors
     */
    public function __construct(
        #[AutowireIterator(OverviewContributorInterface::TAG)]
        private iterable $contributors,
    ) {
    }

    /**
     * The contributors this area has, in the order the library heads them.
     *
     * The area's installed module slugs are handed IN rather than looked up, so
     * the catalogue, the composer and the page can never disagree about what the
     * area has — they are all reading one answer, fetched once.
     *
     * @param list<string> $installedSlugs in the area's own module order
     *
     * @return list<OverviewContributorInterface>
     */
    public function contributorsFor(array $installedSlugs): array
    {
        $installed = array_flip($installedSlugs);

        $host = [];
        $modules = [];
        $seam = [];
        foreach ($this->contributors as $contributor) {
            $slug = $contributor->moduleSlug();
            match (true) {
                // The host's own sections bracket the library: what the area is
                // at the top, what it does not have at the bottom.
                self::SEAM_SLUG === $slug => $seam[] = $contributor,
                self::HOST_SLUG === $slug => $host[] = $contributor,
                // A module that is not switched on here contributes nothing — not
                // an empty section, nothing. Its widgets leave the library.
                isset($installed[$slug]) => $modules[$installed[$slug]] = $contributor,
                default => null,
            };
        }
        ksort($modules);

        return [...$host, ...array_values($modules), ...$seam];
    }

    /**
     * @param list<string> $installedSlugs
     */
    public function for(array $installedSlugs): WidgetCatalog
    {
        $groups = [];
        $widgets = [];
        foreach ($this->contributorsFor($installedSlugs) as $contributor) {
            $groups[] = $contributor->group();
            foreach ($contributor->widgets() as $widget) {
                $widgets[] = $widget;
            }
        }

        $available = array_column($widgets, 'id');

        return new WidgetCatalog(
            self::SURFACE,
            $groups,
            $widgets,
            self::presetsFor($available),
            WidgetCatalog::DEFAULT_PRESET_ID,
            AreaOverviewPresets::SHIPPED_LABEL,
            AreaOverviewPresets::SHIPPED_DESCRIPTION,
        );
    }

    /**
     * Widget id => the Twig partial that draws it — one namespace per
     * contributor, which is why the host's own template can contain no widget
     * markup and name no module.
     *
     * @param list<string> $installedSlugs
     *
     * @return array<string, string>
     */
    public function partialsFor(array $installedSlugs): array
    {
        $partials = [];
        foreach ($this->contributorsFor($installedSlugs) as $contributor) {
            foreach ($contributor->widgets() as $widget) {
                $partials[$widget->id] = \sprintf($contributor->partialPattern(), $widget->id);
            }
        }

        return $partials;
    }

    /**
     * The stylesheet of every installed contributor that has one, in the order
     * the library heads them.
     *
     * A MODULE'S WIDGETS WEAR THE MODULE'S OWN VOCABULARY, and on this surface
     * the host renders them — so unless the host loads each installed module's
     * CSS, every chip on a contributed widget renders naked. Nowhere else does
     * this arise: a module's own pages extend the module's own layout.
     *
     * @param list<string> $installedSlugs
     *
     * @return list<string>
     */
    public function stylesheetsFor(array $installedSlugs): array
    {
        $sheets = [];
        foreach ($this->contributorsFor($installedSlugs) as $contributor) {
            if ($contributor instanceof ContributesStylesheetInterface) {
                $sheets[] = $contributor->stylesheet();
            }
        }

        return array_values(array_unique($sheets));
    }

    /**
     * The five directions, each trimmed to what this area has.
     *
     * @param list<string> $available
     *
     * @return list<WidgetPreset>
     */
    private static function presetsFor(array $available): array
    {
        $presets = [];
        foreach (AreaOverviewPresets::directions() as $id => [$label, $description, $layout]) {
            $trimmed = array_filter(
                $layout,
                static fn (string $widgetId): bool => \in_array($widgetId, $available, true),
                \ARRAY_FILTER_USE_KEY,
            );
            if ([] === $trimmed) {
                continue;
            }
            $presets[] = new WidgetPreset($id, $label, $description, $trimmed);
        }

        return $presets;
    }
}
