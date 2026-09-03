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

namespace Uhifadhi\Overview;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Model\Widget;
use Uhifadhi\Model\WidgetGroup;
use Uhifadhi\Repository\AreaOfInterestRepository;
use Uhifadhi\Repository\ZoneRepository;
use Uhifadhi\Service\AreaCardService;
use Uhifadhi\Service\AreaOverviewCatalogue;
use UhifadhiLabs\Trunk\Service\AreaModuleLedger;

/**
 * THE HOST'S OWN CONTRIBUTION TO THE AREA OVERVIEW — and almost nothing on the
 * page is it.
 *
 * What the host owns here is the surface, the grid, the preset framework and the
 * IDENTITY of the area: what this place is, where it is, and which modules it
 * has. Two of its widgets — the right-now strip and needs attention — draw
 * nothing of their own at all: they lay out parts CONTRIBUTED by the modules,
 * which is what stops the two widgets a page like this always grows from
 * becoming a hard-coded list of every module the product ever shipped.
 *
 * `#[AutoconfigureTag]` sits on THIS class rather than on the interface because
 * Symfony reads autoconfigure attributes off the definition's own class and PHP
 * does not inherit attributes from an interface — see the interface's docblock.
 */
#[AutoconfigureTag(OverviewContributorInterface::TAG)]
final readonly class HostOverviewContributor implements OverviewContributorInterface
{
    public function __construct(
        private AreaOfInterestRepository $areas,
        private ZoneRepository $zones,
        private AreaModuleLedger $ledger,
        private AreaCardService $cards,
    ) {
    }

    public function moduleSlug(): string
    {
        return AreaOverviewCatalogue::HOST_SLUG;
    }

    public function group(): WidgetGroup
    {
        return new WidgetGroup(
            AreaOverviewCatalogue::HOST_SLUG,
            'The area itself',
            'The host’s own widgets: who this area is, the operational map plate, what needs attention across every module, and who is on. These are here whichever modules are installed — and two of them (the right-now strip and needs attention) render parts CONTRIBUTED by the modules rather than a list the host wrote.',
        );
    }

    public function widgets(): array
    {
        $host = AreaOverviewCatalogue::HOST_SLUG;

        // Declaration order IS the shipped composition: who you are, what is
        // happening now, what needs you, where, who is on, what is installed.
        // Direction-neutral on purpose — the five sharper readings are presets.
        return [
            new Widget('ident', 'Identity band', $host, 12, [12], note: 'Size, zones, IUCN category, established, coordinates — one quiet line, never a row of plates.'),
            new Widget('nowbar', 'Right now', $host, 12, [12], note: 'The live strip. Each tile is contributed by a module; the host only lays them out and orders them.'),
            new Widget('attention', 'Needs attention', $host, 12, [12, 9, 6], note: 'One list, every module. The host sorts by urgency; the items come from the modules.'),
            // WHAT THE HOST ALONE DRAWS. The layers the modules contribute are
            // named by the modules: AreaOverviewCatalogue composes the full line
            // from their phrases as it assembles this area's list, so an area
            // without incidents is never promised open incidents.
            new Widget('map', 'Operational map', $host, 12, [12, 9], note: 'Boundary and stations. Scientific layers are in the legend, switched off.'),
            new Widget('presence', 'Stations &amp; who is on', $host, 6, [12, 9, 6], note: 'Each station, whether it is reporting, and who is on it right now.'),
            new Widget('modules', 'Modules in this area', $host, 6, [12, 9, 6], note: 'What is installed here, what each contributed to this page, and the way to add another.'),
            new Widget('mapdock', 'Operational map + dock', $host, 12, [12, 9], on: false, note: 'The same plate at full height with the live dock beside it — one viewport, one list, no scrolling away from the map.'),
            new Widget('pulse', 'Area pulse', $host, 12, [12, 9, 6], on: false, note: 'One reverse-chronological stream merging every module’s events, each tagged with the module that raised it.'),
            new Widget('board', 'Duty board', $host, 12, [12, 9], on: false, note: 'Every number the area has, dense, at wall-display scale. Each tile is a link into the list behind it.'),
            new Widget('science', 'The scientific record', $host, 6, [12, 9, 6], on: false, note: 'Forest loss and fire detections, in one card, off by default. The old overview’s whole top half, demoted to a widget.'),
        ];
    }

    public function partialPattern(): string
    {
        return 'area/overview/_w_%s.html.twig';
    }

    public function context(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        $centroid = null;
        if (null !== $area->getGeom()) {
            // bounds() answers [lon, lat] and the label reads latitude first.
            [$lon, $lat] = $this->cards->centroid($this->cards->bounds($area->getGeom()));
            $centroid = $this->cards->formatCoords($lat, $lon);
        }

        return [
            'areaKm2' => (int) round($this->areas->stAreaKm2(['id' => $area->getId()])),
            'zoneCount' => \count($this->zones->zonesFor($area)),
            'iucn' => $area->getIucnCategory(),
            'established' => $area->getEstablishedYear(),
            'centroid' => $centroid,
            // WHAT THIS AREA HAS AND WHAT IT DOES NOT, from the one reading the
            // seam's own card reads: AO·08 draws both halves, because a table
            // that lists two rows under the words "8 in the catalogue" leaves a
            // person to wonder what the other six are.
            ...$this->ledger->for($area),
        ];
    }
}
