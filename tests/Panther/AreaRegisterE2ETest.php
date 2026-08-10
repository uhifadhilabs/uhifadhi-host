<?php

declare(strict_types=1);

namespace App\Tests\Panther;

use App\Forest\Factory\ForestLossYearFactory;
use App\Spatial\Factory\AreaOfInterestFactory;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;

/**
 * The areas register controls actually work in a real browser: the filter pills
 * filter, the search searches, and the column headers sort — none are decorative.
 */
#[SkipDatabaseRollback]
final class AreaRegisterE2ETest extends E2ETestCase
{
    public function testTheRegisterControlsFilterSearchAndSort(): void
    {
        // Alpha is ingested (a live module); Zulu is not (queued).
        $alpha = AreaOfInterestFactory::createOne(['name' => 'Alpha park']);
        ForestLossYearFactory::createOne(['aoi' => $alpha, 'year' => 2010, 'areaHa' => 500.0]);
        AreaOfInterestFactory::createOne(['name' => 'Zulu park']);

        $client = static::createPantherClient();
        $client->request('GET', '/');
        $client->waitFor('[data-register-target="row"]');

        $visible = static fn () => $client->getCrawler()
            ->filter('[data-register-target="row"]:not([hidden])')->count();

        // Both rows visible under "All".
        self::assertSame(2, $visible());

        // The "Queued" pill hides the live area.
        $client->getCrawler()->filter('[data-register-target="pill"][data-filter="queued"]')->click();
        $client->waitForInvisibility('[data-register-target="row"][data-live="1"]');
        self::assertSame(1, $visible());

        // Search narrows to a single named area.
        $client->getCrawler()->filter('[data-register-target="pill"][data-filter="all"]')->click();
        $client->getCrawler()->filter('[data-register-target="search"]')->sendKeys('alpha');
        $client->waitForInvisibility('[data-register-target="row"][data-name="zulu park"]');
        self::assertSame(1, $visible());

        // Fresh load (empty search), sort by name ascending → Alpha first.
        $client->request('GET', '/');
        $client->waitFor('[data-register-target="row"]');
        $client->getCrawler()->filter('th[data-sort="name"]')->click();
        $firstName = $client->getCrawler()->filter('[data-register-target="row"]:not([hidden])')->first()->attr('data-name');
        self::assertSame('alpha park', $firstName);
    }
}
