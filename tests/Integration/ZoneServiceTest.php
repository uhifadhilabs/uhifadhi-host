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

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\Zone;
use Uhifadhi\Exception\ZoneOverlapException;
use Uhifadhi\Factory\AreaOfInterestFactory;
use Uhifadhi\Service\ZoneService;
use Uhifadhi\Tests\ZoneGeometry;
use Zenstruck\Foundry\Test\Factories;

/**
 * The zone invariant against real PostGIS: sibling zones may never share INTERIOR.
 * Touching along an edge is legal (that is what adjacency looks like), gaps are legal
 * (partial zoning), containment is not (a zone inside a zone shares its whole interior).
 *
 * zoneOf() is the read side: point-in-zone by ST_Covers, null when the point falls in
 * no zone — a first-class answer, not an error.
 */
final class ZoneServiceTest extends KernelTestCase
{
    use Factories;
    use ZoneGeometry;

    private function service(): ZoneService
    {
        $service = self::getContainer()->get(ZoneService::class);
        \assert($service instanceof ZoneService);

        return $service;
    }

    private function areaWithNorth(): AreaOfInterest
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $this->service()->create($area, 'North', self::square(35.0, -3.4, 35.4, -3.0));

        return $area;
    }

    public function testAZoneOverlappingASiblingIsRejectedNamingIt(): void
    {
        $area = $this->areaWithNorth();

        $this->expectException(ZoneOverlapException::class);
        $this->expectExceptionMessageMatches('/North/');

        // Interiors intersect over lon 35.2–35.4.
        $this->service()->create($area, 'Overlapper', self::square(35.2, -3.2, 35.6, -2.9));
    }

    public function testZonesTouchingAlongAnEdgeAreAccepted(): void
    {
        $area = $this->areaWithNorth();

        // Shares exactly the lon 35.4 edge — boundaries meet, interiors do not.
        $east = $this->service()->create($area, 'East', self::square(35.4, -3.4, 35.8, -3.0));

        self::assertNotNull($east->getId());
    }

    public function testAZoneInsideASiblingIsRejected(): void
    {
        $area = $this->areaWithNorth();

        $this->expectException(ZoneOverlapException::class);
        $this->expectExceptionMessageMatches('/North/');

        // ST_Overlaps would call this false — containment still shares interior.
        $this->service()->create($area, 'Inner', self::square(35.1, -3.3, 35.2, -3.2));
    }

    public function testADisjointZoneIsAccepted(): void
    {
        $area = $this->areaWithNorth();

        // A gap between them is legal: an area may be only partially zoned.
        $far = $this->service()->create($area, 'Far', self::square(35.6, -3.4, 35.8, -3.2));

        self::assertNotNull($far->getId());
    }

    public function testASiblingZoneOfAnotherAreaIsNoConflict(): void
    {
        $this->areaWithNorth();
        $other = AreaOfInterestFactory::createOne();

        // The invariant is per area — the very same polygon is fine next door.
        $twin = $this->service()->create($other, 'North', self::square(35.0, -3.4, 35.4, -3.0));

        self::assertNotNull($twin->getId());
    }

    public function testReplacingAGeometryIgnoresTheZoneItself(): void
    {
        $area = $this->areaWithNorth();
        $zone = $this->service()->create($area, 'East', self::square(35.4, -3.4, 35.8, -3.0));

        // Shrinking East must not read East's own old footprint as a conflict.
        $this->service()->replaceGeometry($zone, self::square(35.5, -3.4, 35.8, -3.0));

        self::assertSame('East', $this->service()->zoneOf($area, 35.6, -3.2)?->getName());
        self::assertNull($this->service()->zoneOf($area, 35.45, -3.2), 'the vacated strip is unzoned again');
    }

    public function testReplacingAGeometryIntoASiblingIsRejected(): void
    {
        $area = $this->areaWithNorth();
        $zone = $this->service()->create($area, 'East', self::square(35.4, -3.4, 35.8, -3.0));

        $this->expectException(ZoneOverlapException::class);
        $this->expectExceptionMessageMatches('/North/');

        $this->service()->replaceGeometry($zone, self::square(35.2, -3.4, 35.8, -3.0));
    }

    public function testZoneOfFindsTheZoneCoveringThePoint(): void
    {
        $area = $this->areaWithNorth();

        $zone = $this->service()->zoneOf($area, 35.2, -3.2);

        self::assertInstanceOf(Zone::class, $zone);
        self::assertSame('North', $zone->getName());
    }

    public function testZoneOfReturnsNullWhereNothingIsZoned(): void
    {
        $area = $this->areaWithNorth();

        // Outside every zone — "unzoned" is an answer, not a failure.
        self::assertNull($this->service()->zoneOf($area, 35.7, -2.95));
    }

    public function testZoneOfIsDeterministicOnASharedEdge(): void
    {
        $area = $this->areaWithNorth();
        $this->service()->create($area, 'East', self::square(35.4, -3.4, 35.8, -3.0));

        // lon 35.4 belongs to BOTH zones' boundaries (ST_Covers is true for each). The
        // documented tie-break — lowest name, then lowest id — makes the answer stable.
        $onTheEdge = $this->service()->zoneOf($area, 35.4, -3.2);

        self::assertSame('East', $onTheEdge?->getName());
        self::assertSame('East', $this->service()->zoneOf($area, 35.4, -3.2)?->getName(), 'and stable across calls');
    }

    public function testZoneOfIsScopedToItsArea(): void
    {
        $this->areaWithNorth();
        $other = AreaOfInterestFactory::createOne();

        self::assertNull($this->service()->zoneOf($other, 35.2, -3.2));
    }
}
