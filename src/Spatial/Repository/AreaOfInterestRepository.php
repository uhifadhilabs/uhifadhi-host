<?php

declare(strict_types=1);

namespace App\Spatial\Repository;

use App\Spatial\Entity\AreaOfInterest;
use Doctrine\Persistence\ManagerRegistry;
use FundiStadi\PostGISBundle\Repository\SpatialEntityRepository;

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
}
