<?php

declare(strict_types=1);

namespace Uhifadhi\Composition\Repository;

use Uhifadhi\Composition\Entity\AreaModule;
use Uhifadhi\Spatial\Entity\AreaOfInterest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AreaModule>
 */
final class AreaModuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AreaModule::class);
    }

    /**
     * Every module assigned to an area (active and parked), in sub-nav order.
     *
     * @return list<AreaModule>
     */
    public function forArea(AreaOfInterest $area): array
    {
        return $this->orderedFor($area, onlyActive: false);
    }

    /**
     * Only the active modules of an area, in sub-nav order — what the sub-nav renders.
     *
     * @return list<AreaModule>
     */
    public function activeForArea(AreaOfInterest $area): array
    {
        return $this->orderedFor($area, onlyActive: true);
    }

    /**
     * @return list<AreaModule>
     */
    private function orderedFor(AreaOfInterest $area, bool $onlyActive): array
    {
        $qb = $this->createQueryBuilder('am')
            ->join('am.module', 'm')
            ->addSelect('m')
            ->andWhere('am.area = :area')
            ->setParameter('area', $area)
            ->orderBy('am.position', 'ASC');

        if ($onlyActive) {
            $qb->andWhere('am.active = true');
        }

        /** @var list<AreaModule> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}
