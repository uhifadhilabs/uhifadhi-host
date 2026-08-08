<?php

declare(strict_types=1);

namespace App\Tests\Functional\Forest;

use App\Forest\Factory\ForestLossYearFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The GeoJSON endpoint the map fetches its loss layer from.
 */
final class ForestLossApiTest extends WebTestCase
{
    use Factories;

    public function testTheEndpointServesAFeatureCollectionOrderedByYear(): void
    {
        $client = static::createClient();
        ForestLossYearFactory::createOne(['year' => 2019, 'areaHa' => 69.0]);
        ForestLossYearFactory::createOne(['year' => 2004, 'areaHa' => 53.0]);

        $client->request('GET', '/api/forest-loss.geojson');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        /** @var array{type: string, features: list<array{type: string, properties: array{year: int, areaHa: float}, geometry: array{type: string}}>} $doc */
        $doc = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('FeatureCollection', $doc['type']);
        self::assertCount(2, $doc['features']);
        // Ordered by year ascending, geometry round-tripped through PostGIS as MultiPolygon.
        self::assertSame([2004, 2019], array_column(array_column($doc['features'], 'properties'), 'year'));
        self::assertSame('MultiPolygon', $doc['features'][0]['geometry']['type']);
        // JSON has no int/float distinction: 53.0 serialises as 53.
        self::assertSame(53.0, (float) $doc['features'][0]['properties']['areaHa']);
    }

    public function testTheEndpointServesAnEmptyCollectionWithoutData(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/forest-loss.geojson');

        self::assertResponseIsSuccessful();
        /** @var array{type: string, features: list<mixed>} $doc */
        $doc = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['type' => 'FeatureCollection', 'features' => []], $doc);
    }
}
