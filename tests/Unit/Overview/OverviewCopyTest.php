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
use Uhifadhi\Overview\OverviewCopyProviderInterface;
use Uhifadhi\Service\OverviewCopy;

/**
 * THE HOST'S SENTENCES, WITH AND WITHOUT THE MODULES THAT FINISH THEM.
 *
 * The design's page has patrols and incidents installed, so the design's wording
 * is the BOTH case — and it has to come out of the composition to the character,
 * because a seam that produces nearly the design is a redesign nobody agreed to.
 * The other two cases are the reason the seam exists at all: an area with one
 * module must not be promised the other's layers, and an area with none must not
 * be promised anything.
 */
final class OverviewCopyTest extends TestCase
{
    /** @param array<string, list<string>> $fragments slot => phrases */
    private static function provider(string $slug, array $fragments): OverviewCopyProviderInterface
    {
        return new class($slug, $fragments) implements OverviewCopyProviderInterface {
            /** @param array<string, list<string>> $fragments */
            public function __construct(private string $slug, private array $fragments)
            {
            }

            public function moduleSlug(): string
            {
                return $this->slug;
            }

            public function copyFragments(string $slot): array
            {
                return $this->fragments[$slot] ?? [];
            }
        };
    }

    private static function copy(): OverviewCopy
    {
        return new OverviewCopy([
            self::provider('patrols', [
                OverviewCopyProviderInterface::SLOT_MAP_LAYERS => ['today’s tracks'],
                OverviewCopyProviderInterface::SLOT_MAP_GROUND_SPOTTING => ['a stranded patrol', 'an unwatched corner'],
            ]),
            self::provider('incidents', [
                OverviewCopyProviderInterface::SLOT_MAP_LAYERS => ['open incidents'],
            ]),
        ]);
    }

    /**
     * THE DESIGN'S WORDING, TO THE CHARACTER — the whole point of composing
     * rather than writing it out.
     */
    public function testBothModulesInstalledReadsExactlyAsTheDesignDraws(): void
    {
        $copy = self::copy();
        $installed = ['patrols', 'incidents'];

        self::assertSame(
            'Boundary, stations, today’s tracks and open incidents. Scientific layers are in the legend, switched off.',
            $copy->mapNote($installed),
        );
        self::assertSame(
            'The area IS the map: it takes the height of the screen and everything else docks to it, so “where” is answered before “what”. Unbeatable for spotting a cluster, a stranded patrol or an unwatched corner; worst for money, paperwork and anything that has no coordinates.',
            $copy->mapGroundThesis($installed),
        );
    }

    /**
     * ONE MODULE SAYS LESS AND STAYS TRUE. The note used to promise open
     * incidents to an area with no incidents register, because the host had the
     * words written into it.
     */
    public function testOneModuleInstalledSaysOnlyWhatThatModuleDraws(): void
    {
        $copy = self::copy();

        self::assertSame(
            'Boundary, stations and today’s tracks. Scientific layers are in the legend, switched off.',
            $copy->mapNote(['patrols']),
        );
        self::assertStringContainsString(
            'spotting a cluster, a stranded patrol or an unwatched corner;',
            $copy->mapGroundThesis(['patrols']),
        );

        self::assertSame(
            'Boundary, stations and open incidents. Scientific layers are in the legend, switched off.',
            $copy->mapNote(['incidents']),
        );
        // Incidents says nothing about what a map is worth adopting FOR, and a
        // slot a module is silent in is an ordinary answer, not a gap.
        self::assertStringContainsString('spotting a cluster;', $copy->mapGroundThesis(['incidents']));
    }

    /**
     * WITH NOTHING INSTALLED THE HOST SAYS ONLY WHAT IT DRAWS ITSELF. The
     * boundary and the stations are its own layers; everything operational was
     * somebody else's word.
     */
    public function testNoModuleInstalledLeavesTheHostsOwnSentence(): void
    {
        $copy = self::copy();

        self::assertSame(
            'Boundary and stations. Scientific layers are in the legend, switched off.',
            $copy->mapNote([]),
        );
        self::assertStringContainsString('Unbeatable for spotting a cluster;', $copy->mapGroundThesis([]));
    }

    /**
     * NOT INSTALLED HERE MEANS NOT ASKED. A registered provider whose module the
     * area has switched off may not put a word on that area's page — the same
     * rule the tiles, the items and the layers are held to.
     */
    public function testAModuleTheAreaDoesNotHaveContributesNoWords(): void
    {
        self::assertStringNotContainsString('open incidents', self::copy()->mapNote(['patrols']));
    }

    /**
     * THE SENTENCE READS IN THE ORDER THE PAGE DOES — the area's own module
     * order, which is the order the widget library heads its sections in.
     */
    public function testThePhrasesFollowTheAreasOwnModuleOrder(): void
    {
        self::assertSame(
            'Boundary, stations, open incidents and today’s tracks. Scientific layers are in the legend, switched off.',
            self::copy()->mapNote(['incidents', 'patrols']),
        );
    }
}
