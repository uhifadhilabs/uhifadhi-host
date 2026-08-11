<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dashboard;

use App\Dashboard\Service\AreaModuleService;
use PHPUnit\Framework\TestCase;

final class AreaModuleServiceTest extends TestCase
{
    public function testTheTabsAreOrderedWithOverviewFirstAndForestLive(): void
    {
        $service = new AreaModuleService();
        $modules = $service->modules();

        self::assertSame('overview', $modules[0]['slug']);
        self::assertSame('forest', $modules[1]['slug']);
        self::assertSame('live', $modules[1]['status']);

        // Every module carries a label + blurb, and there are all eleven tabs.
        self::assertCount(11, $modules);
        foreach ($modules as $m) {
            self::assertNotSame('', $m['label']);
            self::assertNotSame('', $m['blurb']);
        }
    }

    public function testPlannedTabsAreListedButNotRoutable(): void
    {
        $service = new AreaModuleService();

        self::assertSame(['fires', 'water', 'roads'], array_column($service->planned(), 'slug'));
        // Planned tabs have no page — the router must 404 them.
        self::assertNull($service->page('fires'));
    }

    public function testPageResolvesLiveAndTemplateModulesButNotOverviewOrUnknown(): void
    {
        $service = new AreaModuleService();

        self::assertSame('Climate', $service->page('climate')['label']);
        self::assertSame('template', $service->page('climate')['status']);
        self::assertSame('Forest loss', $service->page('forest')['label']);

        // The Overview tab is the hub (the show page), not a module page.
        self::assertNull($service->page('overview'));
        self::assertNull($service->page('does-not-exist'));
    }
}
