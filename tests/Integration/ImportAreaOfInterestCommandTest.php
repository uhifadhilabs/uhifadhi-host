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

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Uhifadhi\Repository\AreaOfInterestRepository;

/**
 * The AOI importer against the REAL PostGIS: the GeoJSON string must round-trip
 * through ST_GeomFromGeoJSON into a typed geometry(MultiPolygon,4326) column.
 */
final class ImportAreaOfInterestCommandTest extends KernelTestCase
{
    private function commandTester(): CommandTester
    {
        self::bootKernel();

        return new CommandTester(new Application(self::$kernel)->find('app:aoi:import'));
    }

    public function testAFeatureFileIsImportedAsARealMultiPolygon(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'aoi').'.geojson';
        file_put_contents($file, (string) json_encode([
            'type' => 'Feature',
            'properties' => ['name' => 'square'],
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[[35.0, -3.4], [35.8, -3.4], [35.8, -2.9], [35.0, -2.9], [35.0, -3.4]]],
            ],
        ]));

        $tester = $this->commandTester();
        $exit = $tester->execute(['name' => 'Test boundary', 'file' => $file, '--source' => 'unit']);
        unlink($file);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Imported "Test boundary"', $tester->getDisplay());

        $repository = self::getContainer()->get(AreaOfInterestRepository::class);
        $aoi = $repository->findOneBy(['name' => 'Test boundary']);
        self::assertNotNull($aoi);
        self::assertSame('unit', $aoi->getSource());

        // Ask PostGIS itself what landed in the column — the geometry must be a
        // typed, SRID-4326 MultiPolygon, not just a stored string.
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var array{t: string, srid: int}|false $row */
        $row = $em->getConnection()->fetchAssociative(
            'SELECT GeometryType(geom) AS t, ST_SRID(geom) AS srid FROM area_of_interest WHERE id = :id',
            ['id' => $aoi->getId()],
        );
        self::assertNotFalse($row);
        self::assertSame('MULTIPOLYGON', $row['t']);
        self::assertSame(4326, (int) $row['srid']);
    }

    public function testAFileWithoutPolygonalGeometryFails(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'aoi').'.geojson';
        file_put_contents($file, (string) json_encode(['type' => 'Point', 'coordinates' => [35.0, -3.0]]));

        $tester = $this->commandTester();
        $exit = $tester->execute(['name' => 'Bad', 'file' => $file]);
        unlink($file);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Point', $tester->getDisplay());
    }

    public function testAMissingFileFails(): void
    {
        $tester = $this->commandTester();

        $exit = $tester->execute(['name' => 'Missing', 'file' => '/nope/nothing.geojson']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Cannot read', $tester->getDisplay());
    }
}
