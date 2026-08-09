<?php

declare(strict_types=1);

namespace App\Tests\Functional\Dashboard;

use App\Forest\Factory\ForestLossYearFactory;
use App\Ingestion\Message\IngestHansenLoss;
use App\Spatial\Factory\AreaOfInterestFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;

/**
 * The per-area detail: map wiring scoped to the area, metrics, runs, and the
 * ingestion trigger dispatching the async message.
 */
final class AreaDetailTest extends WebTestCase
{
    use Factories;

    public function testTheDetailPageWiresTheMapToThisAreaOnly(): void
    {
        $client = static::createClient();
        $aoi = AreaOfInterestFactory::createOne(['name' => 'Detail area']);
        ForestLossYearFactory::createOne(['aoi' => $aoi, 'year' => 2013, 'areaHa' => 186.0]);

        $crawler = $client->request('GET', '/areas/'.$aoi->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="map"]');
        $wrapper = $crawler->filter('[data-controller="map"]');
        self::assertSame('/api/areas/'.$aoi->getId().'/forest-loss.geojson', $wrapper->attr('data-map-forest-loss-url-value'));
        self::assertStringContainsString('MultiPolygon', (string) $wrapper->attr('data-map-boundary-value'));
        self::assertSelectorTextContains('header', '186 ha');
        self::assertCount(1, $crawler->filter('[data-map-target="bar"]'));
        // The ingestion trigger is present.
        self::assertSelectorExists(\sprintf('form[action="/areas/%d/ingest"]', $aoi->getId()));
    }

    public function testTheIngestButtonDispatchesTheAsyncMessage(): void
    {
        $client = static::createClient();
        $aoi = AreaOfInterestFactory::createOne(['name' => 'Ingest me']);

        $client->request('GET', '/areas/'.$aoi->getId());
        $client->submitForm('Run Hansen forest-loss ingestion');

        self::assertResponseRedirects('/areas/'.$aoi->getId());

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $messages = array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent());
        self::assertCount(1, $messages);
        self::assertInstanceOf(IngestHansenLoss::class, $messages[0]);
        self::assertSame($aoi->getId(), $messages[0]->aoiId);
    }

    public function testAMissingAreaIs404(): void
    {
        $client = static::createClient();

        $client->request('GET', '/areas/999999');

        self::assertResponseStatusCodeSame(404);
    }
}
