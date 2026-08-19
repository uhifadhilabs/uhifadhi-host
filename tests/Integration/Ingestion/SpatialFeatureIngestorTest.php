<?php

declare(strict_types=1);

namespace Uhifadhi\Tests\Integration\Ingestion;

use Uhifadhi\Ingestion\Repository\ModuleFeatureRepository;
use Uhifadhi\Ingestion\Service\SpatialFeatureIngestor;
use Uhifadhi\Spatial\Factory\AreaOfInterestFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The generic vector ingest: raw polygons (one per pixel-cluster, tagged by label) are staged, then
 * PostGIS dissolves them into one MultiPolygon per label — the map layer. Mirrors the Hansen dissolve.
 */
final class SpatialFeatureIngestorTest extends KernelTestCase
{
    use Factories;

    private function poly(float $x, float $y): array
    {
        // A 0.01°-square polygon anchored at (x, y).
        return ['type' => 'Polygon', 'coordinates' => [[
            [$x, $y], [$x + 0.01, $y], [$x + 0.01, $y + 0.01], [$x, $y + 0.01], [$x, $y],
        ]]];
    }

    public function testItDissolvesRawPolygonsIntoOneMultiPolygonPerLabel(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $ingestor = self::getContainer()->get(SpatialFeatureIngestor::class);
        $features = self::getContainer()->get(ModuleFeatureRepository::class);
        \assert($ingestor instanceof SpatialFeatureIngestor);
        \assert($features instanceof ModuleFeatureRepository);

        $ingestor->ingest($area, 'landcover', 'landcover_map', [
            ['label' => 'Grassland', 'geometry' => $this->poly(35.0, -3.0)],
            ['label' => 'Grassland', 'geometry' => $this->poly(35.2, -3.0)], // second grassland patch
            ['label' => 'Tree cover', 'geometry' => $this->poly(35.4, -3.0)],
        ]);

        $layer = $features->forLayer($area, 'landcover', 'landcover_map');
        self::assertSame(['Grassland', 'Tree cover'], array_map(static fn ($f) => $f->getLabel(), $layer));
        // Each row is a valid MultiPolygon GeoJSON.
        self::assertStringContainsString('MultiPolygon', (string) $layer[0]->getGeom());
    }

    public function testARerunReplacesTheLayer(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $ingestor = self::getContainer()->get(SpatialFeatureIngestor::class);
        $features = self::getContainer()->get(ModuleFeatureRepository::class);
        \assert($ingestor instanceof SpatialFeatureIngestor);
        \assert($features instanceof ModuleFeatureRepository);

        $ingestor->ingest($area, 'landcover', 'landcover_map', [
            ['label' => 'Grassland', 'geometry' => $this->poly(35.0, -3.0)],
            ['label' => 'Cropland', 'geometry' => $this->poly(35.2, -3.0)],
        ]);
        $ingestor->ingest($area, 'landcover', 'landcover_map', [
            ['label' => 'Grassland', 'geometry' => $this->poly(35.0, -3.0)],
        ]);

        $layer = $features->forLayer($area, 'landcover', 'landcover_map');
        self::assertSame(['Grassland'], array_map(static fn ($f) => $f->getLabel(), $layer), 'the rerun replaced the layer');
    }
}
