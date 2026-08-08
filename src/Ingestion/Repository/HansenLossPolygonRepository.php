<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\HansenLossPolygon;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HansenLossPolygon>
 */
class HansenLossPolygonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HansenLossPolygon::class);
    }

    /** Empties the staging table (DQL bulk delete — no raw SQL). */
    public function truncate(): void
    {
        $this->getEntityManager()
            ->createQuery(\sprintf('DELETE FROM %s p', HansenLossPolygon::class))
            ->execute();
    }
}
