<?php

declare(strict_types=1);

namespace Uhifadhi\Ingestion\Repository;

use Uhifadhi\Ingestion\Entity\ModuleFeature;
use Uhifadhi\Spatial\Entity\AreaOfInterest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ModuleFeature>
 */
class ModuleFeatureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModuleFeature::class);
    }

    /**
     * The dissolved features of one layer (area + module + dataset key), ordered by label.
     *
     * @return list<ModuleFeature>
     */
    public function forLayer(AreaOfInterest $area, string $moduleSlug, string $key): array
    {
        return $this->findBy(
            ['aoi' => $area, 'moduleSlug' => $moduleSlug, 'datasetKey' => $key],
            ['label' => 'ASC'],
        );
    }
}
