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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\Zone;
use Uhifadhi\Exception\ZoneImportException;
use Uhifadhi\Factory\AreaOfInterestFactory;
use Uhifadhi\Repository\ZoneRepository;
use Uhifadhi\Service\ZoneImportService;
use Uhifadhi\Service\ZoneService;
use Uhifadhi\Tests\ZoneGeometry;
use Zenstruck\Foundry\Test\Factories;

/**
 * Importing a zoning scheme is ALL OR NOTHING: every feature is validated against the
 * zones already stored AND against the other features in the same file before anything
 * is written, so a rejected file leaves the area exactly as it was.
 */
final class ZoneImportServiceTest extends KernelTestCase
{
    use Factories;
    use ZoneGeometry;

    private function importer(): ZoneImportService
    {
        $service = self::getContainer()->get(ZoneImportService::class);
        \assert($service instanceof ZoneImportService);

        return $service;
    }

    private function zones(): ZoneRepository
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $repo = $em->getRepository(Zone::class);
        \assert($repo instanceof ZoneRepository);

        return $repo;
    }

    /**
     * @return list<string|null>
     */
    private function names(AreaOfInterest $area): array
    {
        return array_map(static fn (Zone $z): ?string => $z->getName(), $this->zones()->zonesFor($area));
    }

    /**
     * Three adjacent strips sharing edges — the shape a real zoning scheme has.
     *
     * @return array<string, mixed>
     */
    private function threeStrips(): array
    {
        return self::featureCollection([
            self::squareFeature('North', 35.0, -3.4, 35.2, -3.0),
            self::squareFeature('Central', 35.2, -3.4, 35.4, -3.0),
            self::squareFeature('South', 35.4, -3.4, 35.6, -3.0),
        ]);
    }

    public function testAFeatureCollectionBecomesOneZonePerFeature(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();

        $imported = $this->importer()->importFeatureCollection($area, $this->threeStrips());

        self::assertCount(3, $imported);
        self::assertSame(['Central', 'North', 'South'], $this->names($area));
        foreach ($imported as $zone) {
            self::assertNotNull($zone->getId());
            self::assertNotNull($zone->getUuid());
        }
    }

    public function testTwoOverlappingFeaturesInTheSameFileRejectTheWholeImport(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $collection = self::featureCollection([
            self::squareFeature('North', 35.0, -3.4, 35.4, -3.0),
            self::squareFeature('Middle', 35.2, -3.4, 35.6, -3.0),
        ]);

        try {
            $this->importer()->importFeatureCollection($area, $collection);
            self::fail('the overlapping pair should have been rejected');
        } catch (ZoneImportException $e) {
            self::assertMatchesRegularExpression('/Middle/', $e->getMessage());
            self::assertMatchesRegularExpression('/North/', $e->getMessage(), 'the overlap partner is named too');
        }

        self::assertSame([], $this->names($area), 'nothing at all was written');
    }

    public function testAFeatureOverlappingAnExistingZoneRejectsTheWholeImport(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $service = self::getContainer()->get(ZoneService::class);
        \assert($service instanceof ZoneService);
        $service->create($area, 'Existing', self::square(35.0, -3.4, 35.4, -3.0));

        $collection = self::featureCollection([
            self::squareFeature('Clean', 35.5, -3.4, 35.6, -3.0),
            self::squareFeature('Trespasser', 35.3, -3.4, 35.45, -3.0),
        ]);

        try {
            $this->importer()->importFeatureCollection($area, $collection);
            self::fail('the trespassing feature should have been rejected');
        } catch (ZoneImportException $e) {
            self::assertMatchesRegularExpression('/Trespasser/', $e->getMessage());
            self::assertMatchesRegularExpression('/Existing/', $e->getMessage());
        }

        self::assertSame(['Existing'], $this->names($area), 'the clean feature was not written either');
    }

    public function testAFeatureWithoutANameRejectsTheWholeImport(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $nameless = self::squareFeature('x', 35.5, -3.4, 35.6, -3.0);
        $nameless['properties'] = [];

        try {
            $this->importer()->importFeatureCollection($area, self::featureCollection([
                self::squareFeature('Clean', 35.0, -3.4, 35.2, -3.0),
                $nameless,
            ]));
            self::fail('the nameless feature should have been rejected');
        } catch (ZoneImportException $e) {
            self::assertMatchesRegularExpression('/feature #2/i', $e->getMessage());
        }

        self::assertSame([], $this->names($area));
    }

    public function testAFeatureReusingAnExistingZoneNameRejectsTheWholeImport(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $service = self::getContainer()->get(ZoneService::class);
        \assert($service instanceof ZoneService);
        $service->create($area, 'North', self::square(35.0, -3.4, 35.4, -3.0));

        try {
            $this->importer()->importFeatureCollection($area, self::featureCollection([
                self::squareFeature('North', 35.5, -3.4, 35.6, -3.0),
            ]));
            self::fail('the duplicate name should have been rejected');
        } catch (ZoneImportException $e) {
            self::assertMatchesRegularExpression('/North/', $e->getMessage());
        }

        self::assertSame(['North'], $this->names($area));
    }

    public function testAFileIsReadAndImported(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $file = tempnam(sys_get_temp_dir(), 'zones').'.geojson';
        file_put_contents($file, (string) json_encode($this->threeStrips(), \JSON_THROW_ON_ERROR));

        $imported = $this->importer()->importFile($area, $file);
        unlink($file);

        self::assertCount(3, $imported);
    }

    public function testAnUnreadableFileIsReportedNotFatal(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();

        $this->expectException(ZoneImportException::class);
        $this->expectExceptionMessageMatches('/read/i');

        $this->importer()->importFile($area, '/nope/zones.geojson');
    }

    public function testTheSingleZonePathTakesANameAndAGeometry(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();

        // The future draw-on-map flow: one polygon, one name, same invariant.
        $zone = $this->importer()->importZone($area, 'Drawn', [
            'type' => 'Polygon',
            'coordinates' => [[[35.0, -3.4], [35.2, -3.4], [35.2, -3.0], [35.0, -3.0], [35.0, -3.4]]],
        ]);

        self::assertSame('Drawn', $zone->getName());
        self::assertSame(['Drawn'], $this->names($area));

        $this->expectException(ZoneImportException::class);
        $this->importer()->importZone($area, 'Drawn again', [
            'type' => 'Polygon',
            'coordinates' => [[[35.1, -3.3], [35.3, -3.3], [35.3, -3.1], [35.1, -3.1], [35.1, -3.3]]],
        ]);
    }
}
