<?php

declare(strict_types=1);

namespace App\Access\Repository;

use App\Access\Entity\Position;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Position>
 */
final class PositionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Position::class);
    }

    /**
     * Every position, ordered by name — for the /team admin index.
     *
     * @return list<Position>
     */
    public function all(): array
    {
        /** @var list<Position> $result */
        $result = $this->createQueryBuilder('p')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
