<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\Dataset;
use App\Spatial\Entity\AreaOfInterest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Dataset>
 */
final class DatasetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Dataset::class);
    }

    /**
     * The one dataset for an (area, module, key) triple — the read path a visualization binds through.
     */
    public function findOneFor(AreaOfInterest $area, string $moduleSlug, string $key): ?Dataset
    {
        return $this->findOneBy(['area' => $area, 'moduleSlug' => $moduleSlug, 'key' => $key]);
    }

    /**
     * Get-or-create the dataset for an (area, module, key) triple so an ingestion re-run replaces its
     * data in place instead of duplicating it. The returned entity is managed (persisted when new);
     * the caller sets the payload and flushes.
     */
    public function upsert(AreaOfInterest $area, string $moduleSlug, string $key): Dataset
    {
        $dataset = $this->findOneFor($area, $moduleSlug, $key);

        if (null === $dataset) {
            $dataset = (new Dataset())
                ->setArea($area)
                ->setModuleSlug($moduleSlug)
                ->setKey($key);
            $this->getEntityManager()->persist($dataset);
        }

        return $dataset;
    }
}
