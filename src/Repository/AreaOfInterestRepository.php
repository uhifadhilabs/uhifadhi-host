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
