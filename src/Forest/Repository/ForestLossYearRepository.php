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

    /**
     * Removes one area's rows for one provenance source (DQL bulk delete) —
     * the replace semantics of a re-ingestion, scoped so other areas are never
     * touched.
     */
    public function deleteForAoiAndSource(int $aoiId, string $source): void
    {
        $this->getEntityManager()
            ->createQuery(\sprintf('DELETE FROM %s f WHERE f.aoi = :aoi AND f.source = :source', ForestLossYear::class))
            ->setParameter('aoi', $aoiId)
            ->setParameter('source', $source)
            ->execute();
    }
}
