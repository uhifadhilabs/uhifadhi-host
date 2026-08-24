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

namespace Uhifadhi\Repository;

use Doctrine\Persistence\ManagerRegistry;
use FundiStadi\PostGISBundle\Repository\SpatialEntityRepository;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\Zone;

/**
 * Zone lookups, including the two predicates the zone invariant is built on. Both are
 * DE-9IM / coverage questions that the PostGIS bundle's DQL surface does not expose, so
 * they are expressed as native SQL — here, in the repository, and nowhere else in the app.
 *
 * @extends SpatialEntityRepository<Zone>
 */
final class ZoneRepository extends SpatialEntityRepository
{
    /**
     * "The interiors of A and B intersect" — the first cell of the DE-9IM matrix, every
     * other cell free. This is exactly the zone rule: two zones sharing only boundary
     * (adjacent zones) score F in that cell and pass, while an overlap, a containment and
     * an identical footprint all score T and fail. ST_Overlaps alone would not do:
     * PostGIS defines it as false when one geometry contains the other.
     */
    private const string INTERIORS_INTERSECT = 'T********';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Zone::class);
    }

    /**
     * Every zone of one area, by name. An area with no zones — the default state — is an
     * empty list, not an error.
     *
     * @return list<Zone>
     */
    public function zonesFor(AreaOfInterest $area): array
    {
        /** @var list<Zone> $result */
        $result = $this->createQueryBuilder('z')
            ->where('z.area = :area')
            ->setParameter('area', $area)
            ->orderBy('z.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findOneForName(AreaOfInterest $area, string $name): ?Zone
    {
        return $this->findOneBy(['area' => $area, 'name' => $name]);
    }

    /**
     * The sibling zone whose interior the given geometry would share, or null when the
     * geometry fits. $ignore excludes the zone being re-drawn from its own check.
     */
    public function findStInteriorConflict(AreaOfInterest $area, string $geoJson, ?Zone $ignore = null): ?Zone
    {
        $areaId = $area->getId();
        if (null === $areaId) {
            return null;
        }

        $parameters = ['area' => $areaId, 'geom' => $geoJson, 'pattern' => self::INTERIORS_INTERSECT];
        // The && bounding-box test is the index-using prefilter; ST_Relate then decides.
        $sql = 'SELECT z.id FROM zone z WHERE z.area_id = :area'
            .' AND z.geom && ST_GeomFromGeoJSON(:geom)'
            .' AND ST_Relate(z.geom, ST_GeomFromGeoJSON(:geom), :pattern)';
        $ignoreId = $ignore?->getId();
        if (null !== $ignoreId) {
            $sql .= ' AND z.id <> :ignore';
            $parameters['ignore'] = $ignoreId;
        }
        $sql .= ' ORDER BY z.name ASC, z.id ASC LIMIT 1';

        $id = $this->getEntityManager()->getConnection()->fetchOne($sql, $parameters);

        return is_numeric($id) ? $this->find((int) $id) : null;
    }

    /**
     * The zone covering the point, or null where the area is unzoned. ST_Covers includes
     * the boundary, so a point on an edge two zones share matches BOTH; the ordering below
     * settles it deterministically — lowest name, then lowest id.
     */
    public function findStCovering(AreaOfInterest $area, float $lon, float $lat): ?Zone
    {
        $areaId = $area->getId();
        if (null === $areaId) {
            return null;
        }

        $id = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT z.id FROM zone z WHERE z.area_id = :area'
            .' AND ST_Covers(z.geom, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326))'
            .' ORDER BY z.name ASC, z.id ASC LIMIT 1',
            ['area' => $areaId, 'lon' => $lon, 'lat' => $lat],
        );

        return is_numeric($id) ? $this->find((int) $id) : null;
    }

    /**
     * The same interior test between two geometries that are not stored yet — what an
     * import needs to compare the features of one file against each other.
     */
    public function stInteriorsIntersect(string $firstGeoJson, string $secondGeoJson): bool
    {
        // Cast in SQL: the driver hands booleans back as 't'/'f' or 1/0 depending on
        // build, an int is unambiguous.
        $intersects = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT ST_Relate(ST_GeomFromGeoJSON(:first), ST_GeomFromGeoJSON(:second), :pattern)::int',
            ['first' => $firstGeoJson, 'second' => $secondGeoJson, 'pattern' => self::INTERIORS_INTERSECT],
        );

        return is_numeric($intersects) && 1 === (int) $intersects;
    }
}
