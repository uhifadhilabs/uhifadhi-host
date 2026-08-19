<?php

declare(strict_types=1);

namespace Uhifadhi\Tests\Functional\Dashboard;

use Uhifadhi\Ingestion\Enum\DatasetKind;
use Uhifadhi\Ingestion\Factory\DatasetFactory;
use Uhifadhi\Ingestion\Message\RunModuleIngestion;
use Uhifadhi\Spatial\Factory\AreaOfInterestFactory;
use Uhifadhi\Tests\Functional\AuthenticatedWebTestCase;
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
        DatasetFactory::createOne([
            'area' => $aoi, 'moduleSlug' => 'forest', 'key' => 'forest_loss_year',
            'kind' => DatasetKind::Series, 'columns' => ['year', 'ha', 'cumulative_ha'],
            'rows' => [[2013, 186.0, 186.0]],
        ]);

        $crawler = $client->request('GET', '/areas/'.$aoi->getUuidString());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="map"]');
        $wrapper = $crawler->filter('[data-controller="map"]');
        self::assertSame('/api/areas/'.$aoi->getUuidString().'/forest/forest_loss_map.geojson', $wrapper->attr('data-map-forest-loss-url-value'));
        self::assertStringContainsString('MultiPolygon', (string) $wrapper->attr('data-map-boundary-value'));
        // The park-hub KPI plate carries the headline loss figure (unit in its <em>).
        self::assertSelectorTextContains('.kpi.hot', '186');
        self::assertSelectorTextContains('.kpi.hot em', 'ha');
        // The area tabs mark Overview active (Modules & Settings are the other tabs).
        self::assertSelectorTextContains('.atabs a.on', 'Overview');
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
        self::assertInstanceOf(RunModuleIngestion::class, $messages[0]);
        // The generic message: internal id + the module slug — only URLs use the UUID.
        self::assertSame($aoi->getId(), $messages[0]->areaId);
        self::assertSame('forest', $messages[0]->moduleSlug);
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
