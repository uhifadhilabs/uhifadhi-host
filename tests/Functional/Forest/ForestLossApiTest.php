<?php

declare(strict_types=1);

namespace App\Tests\Functional\Forest;

use App\Forest\Factory\ForestLossYearFactory;
use App\Spatial\Factory\AreaOfInterestFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The per-area GeoJSON endpoint the dashboard map fetches — scoped strictly to
 * one area.
 */
final class ForestLossApiTest extends WebTestCase
{
    use Factories;

    public function testTheEndpointServesOnlyTheRequestedAreasFeatures(): void
    {
        $client = static::createClient();
        $mine = AreaOfInterestFactory::createOne();
        $other = AreaOfInterestFactory::createOne();
        ForestLossYearFactory::createOne(['aoi' => $mine, 'year' => 2019, 'areaHa' => 69.0]);
        ForestLossYearFactory::createOne(['aoi' => $mine, 'year' => 2004, 'areaHa' => 53.0]);
        ForestLossYearFactory::createOne(['aoi' => $other, 'year' => 2010, 'areaHa' => 999.0]);

        $client->request('GET', '/api/areas/'.$mine->getUuidString().'/forest-loss.geojson');

        self::assertResponseIsSuccessful();
        /** @var array{type: string, features: list<array{properties: array{year: int}, geometry: array{type: string}}>} $doc */
        $doc = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('FeatureCollection', $doc['type']);
        self::assertCount(2, $doc['features'], 'the other area\'s rows must not leak in');
        self::assertSame([2004, 2019], array_column(array_column($doc['features'], 'properties'), 'year'));
        self::assertSame('MultiPolygon', $doc['features'][0]['geometry']['type']);
    }

    public function testAnUnknownAreaIs404(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/areas/'.\Symfony\Component\Uid\Uuid::v7()->toRfc4122().'/forest-loss.geojson');

        self::assertResponseStatusCodeSame(404);
    }
}
