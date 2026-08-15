<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\ModuleFeatureStaging;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ModuleFeatureStaging>
 */
class ModuleFeatureStagingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModuleFeatureStaging::class);
    }

    /** Empties the staging table (DQL bulk delete — no raw SQL). */
    public function truncate(): void
    {
        $this->getEntityManager()
            ->createQuery(\sprintf('DELETE FROM %s p', ModuleFeatureStaging::class))
            ->execute();
    }
}
