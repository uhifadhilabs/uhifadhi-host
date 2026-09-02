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
use Uhifadhi\Overview\OverviewCopyProviderInterface;

/**
 * THE HOST'S SENTENCES, BUILT ROUND WORDS THE MODULES SUPPLIED.
 *
 * Two strings on the area overview describe what is on the operational plate,
 * and both were written out in full from the design — "today's tracks and open
 * incidents", "a stranded patrol". On any other surface that would be correct
 * copy. Here it was the host naming a module's subject matter on a page whose
 * whole argument is that it cannot, and it went stale the moment an area did not
 * have both modules: a picker note promising open incidents to an area with no
 * incidents register is the same class of lie as a tile reading 0.
 *
 * SO THE CLAUSE IS THE HOST'S AND THE NOUNS ARE THE MODULES'. Each module hands
 * over a phrase through {@see OverviewCopyProviderInterface}; this puts them in
 * the area's own module order, joins them the way English joins a list, and
 * drops them into the sentence. With both modules installed the result is the
 * design's wording to the character. With one, the sentence is shorter and still
 * true. With none, it says only what the host itself draws.
 *
 * THE HOST'S OWN PHRASES ARE HERE, NOT IN A PROVIDER. The boundary and the
 * stations are the host's layers, and "a cluster" is a thing one reads off any
 * map; they are the base every sentence starts from, so a module contributes to
 * a sentence that already says something rather than to an empty one.
 */
final readonly class OverviewCopy
{
    /** The host's own layers on the plate, in the order the design names them. */
    private const array HOST_MAP_LAYERS = ['boundary', 'stations'];

    /** What any map is worth adopting for, before a single module is installed. */
    private const array HOST_MAP_GROUND_SPOTTING = ['a cluster'];

    /**
     * @param iterable<OverviewCopyProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(OverviewCopyProviderInterface::TAG)]
        private iterable $providers,
    ) {
    }

    /**
     * AO·03's line in the widget picker: what a person would be looking at, and
     * what has been demoted off it.
     *
     * @param list<string> $installedSlugs in the area's own module order
     */
    public function mapNote(array $installedSlugs): string
    {
        $phrases = [
            ...self::HOST_MAP_LAYERS,
            ...$this->fragments(OverviewCopyProviderInterface::SLOT_MAP_LAYERS, $installedSlugs),
        ];

        // The second sentence is the host's whole and always: the demotion of the
        // scientific layers is a decision about this page, not about a module.
        return \sprintf(
            '%s. Scientific layers are in the legend, switched off.',
            ucfirst(self::series($phrases, 'and')),
        );
    }

    /**
     * Direction B's thesis — the compare index's own trade-off line, with the
     * middle of it contributed.
     *
     * @param list<string> $installedSlugs in the area's own module order
     */
    public function mapGroundThesis(array $installedSlugs): string
    {
        $phrases = [
            ...self::HOST_MAP_GROUND_SPOTTING,
            ...$this->fragments(OverviewCopyProviderInterface::SLOT_MAP_GROUND_SPOTTING, $installedSlugs),
        ];

        return \sprintf(
            'The area IS the map: it takes the height of the screen and everything else docks to it, so “where” is answered before “what”. Unbeatable for spotting %s; worst for money, paperwork and anything that has no coordinates.',
            self::series($phrases, 'or'),
        );
    }

    /**
     * Every installed module's phrases for one slot, in the area's own module
     * order — which is the order the widget library heads its sections in, so
     * the sentence reads in the same order as the page.
     *
     * NOT INSTALLED HERE MEANS NOT ASKED, exactly as in
     * {@see AreaOverviewComposer}: a module the area has switched off must not be
     * able to put a word on its page.
     *
     * @param list<string> $installedSlugs
     *
     * @return list<string>
     */
    private function fragments(string $slot, array $installedSlugs): array
    {
        $bySlug = [];
        foreach ($this->providers as $provider) {
            $bySlug[$provider->moduleSlug()][] = $provider;
        }

        $phrases = [];
        foreach ($installedSlugs as $slug) {
            foreach ($bySlug[$slug] ?? [] as $provider) {
                foreach ($provider->copyFragments($slot) as $phrase) {
                    if ('' !== $phrase) {
                        $phrases[] = $phrase;
                    }
                }
            }
        }

        return $phrases;
    }

    /**
     * A list, the way English writes one: "a", "a and b", "a, b and c" — with the
     * final conjunction the caller's, because "or" and "and" are two different
     * claims about the same list.
     *
     * @param list<string> $phrases
     */
    private static function series(array $phrases, string $conjunction): string
    {
        if (\count($phrases) < 2) {
            return implode('', $phrases);
        }

        $last = array_pop($phrases);

        return \sprintf('%s %s %s', implode(', ', $phrases), $conjunction, $last);
    }
}
