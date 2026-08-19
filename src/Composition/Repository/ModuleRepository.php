<?php

declare(strict_types=1);

namespace Uhifadhi\Composition\Repository;

use Uhifadhi\Composition\Entity\Module;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Module>
 */
final class ModuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Module::class);
    }

    /**
     * The whole catalogue in display order.
     *
     * @return list<Module>
     */
    public function catalogue(): array
    {
        /** @var list<Module> $result */
        $result = $this->createQueryBuilder('m')
            ->orderBy('m.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findBySlug(string $slug): ?Module
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
