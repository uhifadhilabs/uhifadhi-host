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
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\Zone;
use Uhifadhi\Factory\AreaOfInterestFactory;
use Uhifadhi\Repository\ZoneRepository;
use Uhifadhi\Tests\ZoneGeometry;
use Zenstruck\Foundry\Test\Factories;

/**
 * A zone is a named polygon subdividing one area — the spatial lens. Names are unique
 * per area (two areas may each have a "North"), the geometry lands as a real
 * geometry(MultiPolygon,4326), and the area owns its zones: dropping the area drops them.
 */
final class ZoneRepositoryTest extends KernelTestCase
{
    use Factories;
    use ZoneGeometry;

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function repository(): ZoneRepository
    {
        $repo = $this->em()->getRepository(Zone::class);
        \assert($repo instanceof ZoneRepository);

        return $repo;
    }

    private function persistZone(AreaOfInterest $area, string $name, string $geom): Zone
    {
        $zone = new Zone()->setArea($area)->setName($name)->setGeom($geom);
        $this->em()->persist($zone);
        $this->em()->flush();

        return $zone;
    }

    public function testAZoneRoundTripsWithUuidTimestampsAndATypedGeometry(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();

        $zone = $this->persistZone($area, 'North', self::square(35.0, -3.4, 35.4, -3.0));
        $id = $zone->getId();
        $this->em()->clear();

        $found = $this->repository()->find($id);
        self::assertInstanceOf(Zone::class, $found);
        self::assertNotNull($found->getUuid());
        self::assertNotNull($found->getCreatedAt());
        self::assertNotNull($found->getUpdatedAt());
        self::assertSame('North', (string) $found);
        self::assertSame($area->getId(), $found->getArea()?->getId());

        /** @var array{t: string, srid: int}|false $row */
        $row = $this->em()->getConnection()->fetchAssociative(
            'SELECT GeometryType(geom) AS t, ST_SRID(geom) AS srid FROM zone WHERE id = :id',
            ['id' => $id],
        );
        self::assertNotFalse($row);
        self::assertSame('MULTIPOLYGON', $row['t']);
        self::assertSame(4326, (int) $row['srid']);
    }

    public function testTheNameIsUniquePerAreaOnly(): void
    {
        self::bootKernel();
        $first = AreaOfInterestFactory::createOne();
        $other = AreaOfInterestFactory::createOne();

        $this->persistZone($first, 'North', self::square(35.0, -3.4, 35.4, -3.0));
        // The same name in ANOTHER area is fine — zones are named locally.
        $this->persistZone($other, 'North', self::square(35.0, -3.4, 35.4, -3.0));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->persistZone($first, 'North', self::square(35.5, -3.4, 35.8, -3.0));
    }

    public function testZonesForListsOnlyTheAreasOwnZonesByName(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $other = AreaOfInterestFactory::createOne();

        $this->persistZone($area, 'North', self::square(35.0, -3.4, 35.4, -3.0));
        $this->persistZone($area, 'East', self::square(35.4, -3.4, 35.8, -3.0));
        $this->persistZone($other, 'Elsewhere', self::square(35.0, -3.4, 35.4, -3.0));

        self::assertSame(
            ['East', 'North'],
            array_map(static fn (Zone $z): ?string => $z->getName(), $this->repository()->zonesFor($area)),
        );
    }

    public function testAnAreaWithoutZonesIsTheDefaultState(): void
    {
        self::bootKernel();

        self::assertSame([], $this->repository()->zonesFor(AreaOfInterestFactory::createOne()));
    }

    public function testDroppingTheAreaDropsItsZones(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $zone = $this->persistZone($area, 'North', self::square(35.0, -3.4, 35.4, -3.0));

        // The FK carries ON DELETE CASCADE — deleted at the database, not only in the ORM.
        $this->em()->getConnection()->executeStatement(
            'DELETE FROM area_of_interest WHERE id = :id',
            ['id' => $area->getId()],
        );

        $id = $zone->getId();
        $this->em()->clear();

        self::assertNull($this->repository()->find($id));
    }
}
