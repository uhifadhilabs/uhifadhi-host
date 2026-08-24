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

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\Zone;
use Uhifadhi\Exception\ZoneOverlapException;
use Uhifadhi\Repository\ZoneRepository;

/**
 * The write side of the spatial lens, and the only supported way a zone gets a geometry.
 *
 * THE INVARIANT: sibling zones of one area never share interior. Adjacency is legal — two
 * zones may share an edge — and so are gaps: an area is often only partly zoned. That is
 * precisely the DE-9IM pattern `T********` (interiors intersect), applied in
 * {@see ZoneRepository::findStInteriorConflict()}; a violation names the zone it collided
 * with, since the admin has to go and fix one of the two.
 *
 * The read side is {@see self::zoneOf()}: point-in-zone by ST_Covers, null when the point
 * falls outside every zone. On an edge two zones share, ST_Covers is true for both, so the
 * tie is broken by the zone name (then id) — the answer is stable, and it is documented
 * rather than left to whatever order the planner returns.
 */
final class ZoneService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ZoneRepository $zones,
    ) {
    }

    /**
     * @param string $geomJson a MultiPolygon GeoJSON string in WGS84
     *
     * @throws ZoneOverlapException when the geometry would share interior with a sibling
     */
    public function create(AreaOfInterest $area, string $name, string $geomJson): Zone
    {
        $this->assertFits($area, $name, $geomJson);

        $zone = new Zone()->setArea($area)->setName($name)->setGeom($geomJson);
        $this->em->persist($zone);
        $this->em->flush();

        return $zone;
    }

    /**
     * Re-draws an existing zone. The zone's own current footprint is excluded from the
     * check — otherwise every edit would collide with itself.
     *
     * @throws ZoneOverlapException
     */
    public function replaceGeometry(Zone $zone, string $geomJson): Zone
    {
        $area = $zone->getArea();
        if (null === $area) {
            throw new \LogicException('A zone always belongs to an area.');
        }
        $this->assertFits($area, $zone->getName() ?? '', $geomJson, $zone);

        $zone->setGeom($geomJson);
        $this->em->flush();

        return $zone;
    }

    /**
     * @throws ZoneOverlapException
     */
    public function assertFits(AreaOfInterest $area, string $name, string $geomJson, ?Zone $ignore = null): void
    {
        $conflict = $this->zones->findStInteriorConflict($area, $geomJson, $ignore);
        if (null !== $conflict) {
            throw ZoneOverlapException::between($name, $conflict);
        }
    }

    /**
     * Do two candidate geometries — neither of them stored yet — share interior? What an
     * import needs to check the features of one file against each other.
     */
    public function conflicts(string $firstGeoJson, string $secondGeoJson): bool
    {
        return $this->zones->stInteriorsIntersect($firstGeoJson, $secondGeoJson);
    }

    /**
     * The zone containing the point, or null when the point lies in no zone — an unzoned
     * area, or a gap between zones, is a first-class answer and not an error.
     *
     * A point exactly on an edge two zones share is covered by both; the one whose name
     * sorts first wins (ties broken by id), so repeated calls always answer the same zone.
     */
    public function zoneOf(AreaOfInterest $area, float $lon, float $lat): ?Zone
    {
        return $this->zones->findStCovering($area, $lon, $lat);
    }
}
