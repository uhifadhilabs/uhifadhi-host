<?php

declare(strict_types=1);

namespace App\Composition\Repository;

use App\Composition\Entity\Visualization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Visualization>
 */
final class VisualizationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Visualization::class);
    }
}
