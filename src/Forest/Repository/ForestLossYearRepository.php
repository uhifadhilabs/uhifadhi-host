<?php

declare(strict_types=1);

namespace App\Forest\Repository;

use App\Forest\Entity\ForestLossYear;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ForestLossYear>
 */
class ForestLossYearRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForestLossYear::class);
    }
}
