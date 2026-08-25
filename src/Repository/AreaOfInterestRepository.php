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
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\AreaOfInterest;

/**
 * Extends the PostGIS bundle's repository base (the extend-when-you-need-it
 * rule): the St methods — stAreaKm2(), findStIntersecting() — just exist here,
 * no DQL or SQL in app code.
 *
 * @extends SpatialEntityRepository<AreaOfInterest>
 */
class AreaOfInterestRepository extends SpatialEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AreaOfInterest::class);
    }

    /**
     * Roughly 55 m on the ground. The field app caches the boundary on a phone
     * and draws it at map zooms where a vertex every few metres is invisible,
     * so the full survey geometry would be megabytes spent on nothing.
     * PreserveTopology, not plain Simplify: a boundary that self-intersects
     * after thinning would draw as a torn shape.
     */
    private const float FIELD_SIMPLIFY_DEGREES = 0.0005;

    /**
     * What `GET /api/areas/mine` caches at sign-in (API-CONTRACT.md §3): the
     * identity, the geodesic size and a boundary thinned for a phone.
     *
     * Computed in PostGIS rather than in PHP because both answers are the
     * database's to give — the area is measured on the spheroid, and the
     * simplification has to happen before the geometry is serialized, not after.
     *
     * @return list<array{uuid: string, name: string, areaKm2: float, boundary: string}>
     */
    public function findFieldSummaries(): array
    {
        /** @var list<array{uuid: mixed, name: mixed, area_m2: mixed, boundary: mixed}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select(
                'a.uuid AS uuid',
                'a.name AS name',
                'ST_Area(Geography(a.geom)) AS area_m2',
                'ST_AsGeoJSON(ST_SimplifyPreserveTopology(a.geom, :tolerance)) AS boundary',
            )
            ->setParameter('tolerance', self::FIELD_SIMPLIFY_DEGREES)
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $summaries = [];
        foreach ($rows as $row) {
            $summaries[] = [
                // Doctrine hydrates the `uuid` column type into a Uuid object;
                // a raw string only appears if the mapping ever changes.
                'uuid' => self::uuidString($row['uuid']),
                'name' => \is_string($row['name']) ? $row['name'] : '',
                'areaKm2' => is_numeric($row['area_m2']) ? round((float) $row['area_m2'] / 1e6, 1) : 0.0,
                'boundary' => \is_string($row['boundary']) ? $row['boundary'] : '',
            ];
        }

        return $summaries;
    }

    private static function uuidString(mixed $value): string
    {
        return match (true) {
            $value instanceof Uuid => $value->toRfc4122(),
            \is_string($value) => $value,
            default => '',
        };
    }
}
