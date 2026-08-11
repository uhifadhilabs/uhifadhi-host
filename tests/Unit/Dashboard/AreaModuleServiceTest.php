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

        // Trimmed to the six-question set: Overview + ten modules.
        self::assertCount(11, $modules);
        foreach ($modules as $m) {
            self::assertNotSame('', $m['label']);
            self::assertNotSame('', $m['blurb']);
        }
        // The question-modules are present; covariate/extra modules are dropped.
        $slugs = array_column($modules, 'slug');
        self::assertContains('wildlife', $slugs);
        self::assertContains('structure', $slugs);
        self::assertContains('roads', $slugs);
        self::assertNotContains('climate', $slugs);
        self::assertNotContains('livestock', $slugs);
    }

    public function testNoPlannedModulesAndExtrasAreNotRoutable(): void
    {
        $service = new AreaModuleService();

        // Extras are deferred to the future add-module catalog, not shown as planned tabs.
        self::assertSame([], $service->planned());
        // Dropped extras have no page — the router must 404 them.
        self::assertNull($service->page('climate'));
        self::assertNull($service->page('fires'));
    }

    public function testPageResolvesLiveAndTemplateModulesButNotOverviewOrUnknown(): void
    {
        $service = new AreaModuleService();

        self::assertSame('Wildlife', $service->page('wildlife')['label']);
        self::assertSame('template', $service->page('wildlife')['status']);
        self::assertSame('Forest loss', $service->page('forest')['label']);

        // The Overview tab is the hub (the show page), not a module page.
        self::assertNull($service->page('overview'));
        self::assertNull($service->page('does-not-exist'));
    }
}
