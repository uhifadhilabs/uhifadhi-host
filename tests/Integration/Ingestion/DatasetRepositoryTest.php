<?php

declare(strict_types=1);

namespace Uhifadhi\Tests\Integration\Ingestion;

use Uhifadhi\Ingestion\Entity\Dataset;
use Uhifadhi\Ingestion\Enum\DatasetKind;
use Uhifadhi\Ingestion\Factory\DatasetFactory;
use Uhifadhi\Ingestion\Repository\DatasetRepository;
use Uhifadhi\Spatial\Factory\AreaOfInterestFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * A module's data lives as generic {@see Dataset} rows keyed by (area, module slug, dataset key) —
 * not a per-topic table. `upsert` is the write path an ingestion uses: it get-or-creates that one
 * row so re-running a module replaces its data in place, and `findOneFor` is the read path a
 * visualization binds through. Keys never collide across areas, modules, or dataset keys.
 */
final class DatasetRepositoryTest extends KernelTestCase
{
    use Factories;

    private function repository(): DatasetRepository
    {
        $repo = self::getContainer()->get(DatasetRepository::class);
        \assert($repo instanceof DatasetRepository);

        return $repo;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    public function testUpsertGetOrCreatesOneRowPerAreaModuleKeySoReingestReplacesInPlace(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();

        $repo = $this->repository();
        $first = $repo->upsert($area, 'landcover', 'landcover_area')
            ->setKind(DatasetKind::Series)
            ->setColumns(['class', 'area_km2', 'pct'])
            ->setRows([['Grassland', 5000.0, 61.8]])
            ->setSource('ESA WorldCover 2021 v200');
        $this->em()->flush();

        // A second run of the same module upserts into the SAME row and overwrites its payload.
        $again = $repo->upsert($area, 'landcover', 'landcover_area')
            ->setRows([['Grassland', 5100.0, 62.0], ['Cropland', 12.0, 0.1]]);
        $this->em()->flush();

        self::assertSame($first->getId(), $again->getId(), 'same (area, module, key) upserts into one row');
        self::assertCount(1, $repo->findAll(), 're-ingest must not create a duplicate dataset');
        self::assertSame(
            [['Grassland', 5100.0, 62.0], ['Cropland', 12.0, 0.1]],
            $repo->findOneFor($area, 'landcover', 'landcover_area')?->getRows(),
            'the latest run replaces the stored rows',
        );
    }

    public function testDatasetsAreScopedToAreaModuleAndKey(): void
    {
        self::bootKernel();
        $mine = AreaOfInterestFactory::createOne();
        $other = AreaOfInterestFactory::createOne();
        DatasetFactory::createOne(['area' => $mine, 'moduleSlug' => 'landcover', 'key' => 'landcover_area']);

        $repo = $this->repository();
        self::assertNotNull($repo->findOneFor($mine, 'landcover', 'landcover_area'));
        self::assertNull($repo->findOneFor($other, 'landcover', 'landcover_area'), "another area must not see it");
        self::assertNull($repo->findOneFor($mine, 'landcover', 'fragmentation_class'), 'a different key is a different dataset');
        self::assertNull($repo->findOneFor($mine, 'roads', 'landcover_area'), 'a different module is a different dataset');
    }
}
