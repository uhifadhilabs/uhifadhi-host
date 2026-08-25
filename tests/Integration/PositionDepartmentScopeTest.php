<?php

declare(strict_types=1);

/*
 * This file is part of uhifadhi.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Tests\Integration;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Uhifadhi\Entity\Position;
use Uhifadhi\Factory\DepartmentFactory;
use Uhifadhi\Factory\PositionFactory;
use Uhifadhi\Service\TeamService;
use Zenstruck\Foundry\Test\Factories;

/**
 * THE RULED MODEL CHANGE, at the level that actually enforces it: a position's name is unique
 * inside its DEPARTMENT, not across the org.
 *
 * Two departments may each own an "Analyst" and they share a word and nothing else — different
 * permissions, different holders, different rows. The org-wide reading would have made one of
 * those two organisations rename a job it has had for thirty years.
 *
 * Postgres treats NULLs as distinct, so a single (department_id, name) index would silently
 * stop guarding the unfiled positions — the ones most likely to collide, because "unfiled" is
 * where everything created before departments existed still sits. Hence TWO partial indexes,
 * and hence a test in BOTH directions: same name in two departments is allowed, same name
 * twice in one department (and twice while unfiled) is refused.
 */
final class PositionDepartmentScopeTest extends KernelTestCase
{
    use Factories;

    public function testTwoDepartmentsMayEachOwnAPositionCalledAnalyst(): void
    {
        $ecology = DepartmentFactory::createOne(['name' => 'Ecology']);
        $protection = DepartmentFactory::createOne(['name' => 'Protection Service']);

        PositionFactory::createOne(['name' => 'Analyst', 'department' => $ecology]);
        PositionFactory::createOne(['name' => 'Analyst', 'department' => $protection]);

        self::assertCount(2, $this->em()->getRepository(Position::class)->findBy(['name' => 'Analyst']));
    }

    public function testOneDepartmentMayNotOwnTheSameNameTwice(): void
    {
        $ecology = DepartmentFactory::createOne(['name' => 'Ecology']);
        PositionFactory::createOne(['name' => 'Analyst', 'department' => $ecology]);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->em()->persist(new Position()->setName('Analyst')->setDepartment($ecology));
        $this->em()->flush();
    }

    public function testTheUnfiledHoldingPenIsGuardedTooDespiteItsNullDepartment(): void
    {
        PositionFactory::createOne(['name' => 'Park Manager', 'department' => null]);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->em()->persist(new Position()->setName('Park Manager'));
        $this->em()->flush();
    }

    public function testTheServiceAnswersWhetherANameIsFreeInAGivenDepartmentOnly(): void
    {
        $ecology = DepartmentFactory::createOne(['name' => 'Ecology']);
        $protection = DepartmentFactory::createOne(['name' => 'Protection Service']);
        $analyst = PositionFactory::createOne(['name' => 'Analyst', 'department' => $ecology]);

        $team = static::getContainer()->get(TeamService::class);
        \assert($team instanceof TeamService);

        self::assertFalse($team->nameIsFree($ecology, 'Analyst'), 'taken in Ecology');
        self::assertTrue($team->nameIsFree($protection, 'Analyst'), 'free in Protection Service');
        self::assertTrue($team->nameIsFree(null, 'Analyst'), 'free while unfiled');
        // Renaming a position to the name it already has is not a clash with itself.
        self::assertTrue($team->nameIsFree($ecology, 'Analyst', $analyst));
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
