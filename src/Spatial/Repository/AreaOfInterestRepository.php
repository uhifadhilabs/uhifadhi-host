<?php

declare(strict_types=1);

namespace App\Spatial\Repository;

use App\Spatial\Entity\AreaOfInterest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AreaOfInterest>
 */
class AreaOfInterestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AreaOfInterest::class);
    }

    /**
     * Total geodesic area of all areas of interest, in km² — a headline figure for
     * the dashboard. Computed in PostGIS (ST_Area on the geography cast).
     */
    public function totalAreaKm2(): float
    {
        $sql = 'SELECT COALESCE(SUM(ST_Area(geom::geography)) / 1e6, 0) FROM area_of_interest';
        $value = $this->getEntityManager()->getConnection()->fetchOne($sql);

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
