<?php

declare(strict_types=1);

namespace App\Tests\Functional\Dashboard;

use App\Forest\Factory\ForestLossYearFactory;
use App\Ingestion\Message\IngestHansenLoss;
use App\Spatial\Factory\AreaOfInterestFactory;
use App\Tests\Functional\AuthenticatedWebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The per-area detail: map wiring scoped to the area, metrics, runs, and the
 * ingestion trigger dispatching the async message.
 */
final class AreaDetailTest extends AuthenticatedWebTestCase
{
    public function testTheDetailPageWiresTheMapToThisAreaOnly(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $aoi = AreaOfInterestFactory::createOne(['name' => 'Detail area']);
        ForestLossYearFactory::createOne(['aoi' => $aoi, 'year' => 2013, 'areaHa' => 186.0]);

        $crawler = $client->request('GET', '/areas/'.$aoi->getUuidString());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="map"]');
        $wrapper = $crawler->filter('[data-controller="map"]');
        self::assertSame('/api/areas/'.$aoi->getUuidString().'/forest-loss.geojson', $wrapper->attr('data-map-forest-loss-url-value'));
        self::assertStringContainsString('MultiPolygon', (string) $wrapper->attr('data-map-boundary-value'));
        // The park-hub KPI plate carries the headline loss figure (unit in its <em>).
        self::assertSelectorTextContains('.kpi.hot', '186');
        self::assertSelectorTextContains('.kpi.hot em', 'ha');
        // Module sub-nav marks Overview live and Forest as a working module.
        self::assertSelectorTextContains('.subnav a.on', 'Overview');
        self::assertCount(1, $crawler->filter('[data-map-target="bar"]'));
        // The ingestion trigger is present, addressed by UUID.
        self::assertSelectorExists(\sprintf('form[action="/areas/%s/ingest"]', $aoi->getUuidString()));
    }

    public function testAreasAreAddressedByUuidNotSequentialId(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $aoi = AreaOfInterestFactory::createOne(['name' => 'Uuid only']);

        // The public URL uses the UUID and works.
        $client->request('GET', '/areas/'.$aoi->getUuidString());
        self::assertResponseIsSuccessful();

        // A sequential integer id must NOT resolve — the route only matches UUIDs.
        $client->request('GET', '/areas/'.$aoi->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheIngestButtonDispatchesTheAsyncMessage(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $aoi = AreaOfInterestFactory::createOne(['name' => 'Ingest me']);

        $client->request('GET', '/areas/'.$aoi->getUuidString());
        $client->submitForm('Run Hansen forest-loss ingestion');

        self::assertResponseRedirects('/areas/'.$aoi->getUuidString());

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $messages = array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent());
        self::assertCount(1, $messages);
        self::assertInstanceOf(IngestHansenLoss::class, $messages[0]);
        // The message still carries the internal id — only URLs use the UUID.
        self::assertSame($aoi->getId(), $messages[0]->aoiId);
    }

    public function testAMissingAreaIs404(): void
    {
        $client = static::createClient();
        $this->loginAs($client);

        // A well-formed but unknown UUID resolves to nothing.
        $client->request('GET', '/areas/'.\Symfony\Component\Uid\Uuid::v7()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
    }
}
