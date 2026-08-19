<?php

declare(strict_types=1);

namespace Uhifadhi\Ingestion\Repository;

use Uhifadhi\Ingestion\Entity\DatasetRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DatasetRun>
 */
class DatasetRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DatasetRun::class);
    }
}
