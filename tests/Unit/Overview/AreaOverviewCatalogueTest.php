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
use Uhifadhi\Model\Widget;
use Uhifadhi\Model\WidgetCatalog;
use Uhifadhi\Model\WidgetGroup;
use Uhifadhi\Overview\ContributesStylesheetInterface;
use Uhifadhi\Overview\OverviewContributorInterface;
use Uhifadhi\Service\AreaOverviewCatalogue;
use Uhifadhi\Service\OverviewCopy;

/**
 * THE COMPOSED SURFACE, PROVEN: what an area's overview library holds depends on
 * which modules that area has, and nothing else.
 *
 * These are the assertions that make the open/closed rule real rather than
 * asserted in a comment — install a module and its section, its widgets and the
 * presets that name them appear; uninstall it and all three leave, without the
 * page, the controller or any other module changing.
 */
final class AreaOverviewCatalogueTest extends TestCase
{
    /**
     * A contributor that also ships a stylesheet.
     *
     * @param list<Widget> $widgets
     */
    private static function styledContributor(string $slug, string $label, string $stylesheet, array $widgets): OverviewContributorInterface
    {
        return new class($slug, $label, $stylesheet, $widgets) implements OverviewContributorInterface, ContributesStylesheetInterface {
            /** @param list<Widget> $widgets */
            public function __construct(
                private string $slug,
                private string $label,
                private string $stylesheet,
                private array $widgets,
            ) {
            }

            public function moduleSlug(): string
            {
                return $this->slug;
            }

            public function group(): WidgetGroup
            {
                return new WidgetGroup($this->slug, $this->label, 'What this contributor puts on the page.');
            }

            public function widgets(): array
            {
                return $this->widgets;
            }

            public function partialPattern(): string
            {
                return \sprintf('@%s/overview/_w_%%s.html.twig', ucfirst($this->slug));
            }

            public function context(AreaOfInterest $area, \DateTimeImmutable $now): array
            {
                return ['slug' => $this->slug];
            }

            public function stylesheet(): string
            {
                return $this->stylesheet;
            }
        };
    }

    /** @param list<Widget> $widgets */
    private static function contributor(string $slug, string $label, array $widgets): OverviewContributorInterface
    {
        return new class($slug, $label, $widgets) implements OverviewContributorInterface {
            /** @param list<Widget> $widgets */
            public function __construct(private string $slug, private string $label, private array $widgets)
            {
            }

            public function moduleSlug(): string
            {
                return $this->slug;
            }

            public function group(): WidgetGroup
            {
                return new WidgetGroup($this->slug, $this->label, 'What this contributor puts on the page.');
            }

            public function widgets(): array
            {
                return $this->widgets;
            }

            public function partialPattern(): string
            {
                return \sprintf('@%s/overview/_w_%%s.html.twig', ucfirst($this->slug));
            }

            public function context(AreaOfInterest $area, \DateTimeImmutable $now): array
            {
                return ['slug' => $this->slug];
            }
        };
    }

    private static function catalogue(): AreaOverviewCatalogue
    {
        $host = AreaOverviewCatalogue::HOST_SLUG;
        $seam = AreaOverviewCatalogue::SEAM_SLUG;

        $contributors = [
            // Deliberately declared out of order: the catalogue's own ordering is
            // what puts the host first and the seam last, not the container's.
            self::contributor('incidents', 'Incidents', [
                new Widget('in_flow', 'Open by state', 'incidents', 6, [12, 9, 6]),
            ]),
            self::contributor($seam, 'Not installed in this area', [
                new Widget('nextmod', 'Not installed here', $seam, 12, [12], on: false),
            ]),
            self::contributor($host, 'The area itself', [
                new Widget('ident', 'Identity band', $host, 12, [12]),
                new Widget('attention', 'Needs attention', $host, 12, [12, 9, 6]),
                new Widget('map', 'Operational map', $host, 12, [12, 9]),
                new Widget('pulse', 'Area pulse', $host, 12, [12, 9, 6], on: false),
                new Widget('nowbar', 'Right now', $host, 12, [12]),
                new Widget('presence', 'Stations', $host, 6, [12, 9, 6]),
                new Widget('mapdock', 'Map + dock', $host, 12, [12, 9], on: false),
                new Widget('board', 'Duty board', $host, 12, [12, 9], on: false),
            ]),
            self::styledContributor('patrols', 'Patrols', 'bundles/patrols/patrols.css', [
                new Widget('pl_now', 'Out right now', 'patrols', 6, [12, 9, 6]),
                new Widget('pl_gaps', 'Where nobody has been', 'patrols', 6, [12, 9, 6], on: false),
                new Widget('pl_obsq', 'Observations awaiting action', 'patrols', 12, [12, 9, 6], on: false),
                new Widget('pl_column', 'Patrols — the whole column', 'patrols', 6, [12, 9, 6, 4], on: false),
            ]),
        ];

        return new AreaOverviewCatalogue($contributors, new OverviewCopy([]));
    }

    public function testTheLibrarysHeadedSectionsAreTheHostThenTheModulesThenTheSeam(): void
    {
        // PROVENANCE IS THE GROUP AXIS on this surface, and the order is the
        // reading: what the area is, what each installed module put here, and
        // last, honestly, what it does not have.
        $catalog = self::catalogue()->for(['patrols', 'incidents']);

        self::assertSame(
            [AreaOverviewCatalogue::HOST_SLUG, 'patrols', 'incidents', AreaOverviewCatalogue::SEAM_SLUG],
            array_column($catalog->groups(), 'id'),
        );
    }

    public function testAModuleTheAreaDoesNotHaveContributesNothingAtAll(): void
    {
        $catalog = self::catalogue()->for(['patrols']);

        // Not an empty section — no section. And not one of its widgets.
        self::assertNotContains('incidents', array_column($catalog->groups(), 'id'));
        self::assertFalse($catalog->has('in_flow'));
        self::assertTrue($catalog->has('pl_now'));
    }

    public function testAPresetIsTrimmedToTheWidgetsTheAreaActuallyHas(): void
    {
        // "Attention queue" names two patrols widgets and one incidents widget.
        // With incidents uninstalled the design survives, minus what it cannot
        // draw — the same tolerance a stored preference gets, one step earlier.
        $catalog = self::catalogue()->for(['patrols']);

        $queue = $catalog->preset('e');

        self::assertNotNull($queue);
        self::assertSame(['attention', 'pl_obsq', 'pl_gaps', 'map', 'ident'], $queue->ids());
    }

    public function testAPresetWithNothingLeftToShowIsNotOffered(): void
    {
        // "Module columns" IS its module columns: with no module installed there
        // is no design left, and a card promising a layout that renders an empty
        // page is worse than no card.
        $catalog = self::catalogue()->for([]);

        self::assertNotNull($catalog->preset('a'), 'Pulse first is all host widgets and survives alone.');
        // "The page is literally the sum of its modules" with no module column
        // left would be a band and an empty slot under a thesis it cannot draw.
        self::assertNull($catalog->preset('c'));
    }

    public function testEveryWidgetIsRenderedByItsOwnContributorsTemplate(): void
    {
        // The host template contains no widget markup and names no module: each
        // plate is drawn from the bundle that contributed it.
        $partials = self::catalogue()->partialsFor(['patrols']);

        self::assertSame('@Host/overview/_w_ident.html.twig', $partials['ident'] ?? null);
        self::assertSame('@Patrols/overview/_w_pl_now.html.twig', $partials['pl_now'] ?? null);
        self::assertArrayNotHasKey('in_flow', $partials);
    }

    public function testAContributorThatWearsItsOwnVocabularyIsAskedForItsStylesheet(): void
    {
        // A MODULE'S WIDGETS WEAR THE MODULE'S OWN VOCABULARY. Everywhere else
        // that is free, because a module's pages extend the module's own layout.
        // Here they do not: the host renders them, so the host has to load each
        // installed module's stylesheet or every one of its chips renders naked.
        //
        // An OPTIONAL second interface rather than a method on the first: a
        // contributor with no stylesheet of its own — the host's, the seam's —
        // must not have to answer a question it has no answer to.
        $catalogue = self::catalogue();

        self::assertSame(
            ['bundles/patrols/patrols.css'],
            $catalogue->stylesheetsFor(['patrols']),
        );
        self::assertSame([], $catalogue->stylesheetsFor([]));
    }

    public function testTheCompositionTheHostShipsLeadsTheStripAndIsNoneOfTheFive(): void
    {
        $catalog = self::catalogue()->for(['patrols', 'incidents']);

        $builtins = $catalog->builtins();

        self::assertSame(WidgetCatalog::DEFAULT_PRESET_ID, $builtins[0]->id);
        self::assertSame('The area overview', $builtins[0]->label);
        self::assertSame(WidgetCatalog::DEFAULT_PRESET_ID, $catalog->defaultPresetId());
        self::assertSame(
            [WidgetCatalog::DEFAULT_PRESET_ID, 'a', 'b', 'c', 'd', 'e'],
            array_column($builtins, 'id'),
        );
    }
}
