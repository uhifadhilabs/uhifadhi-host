<?php

declare(strict_types=1);

namespace Uhifadhi\Tests\Integration\Access;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Uhifadhi\Access\Enum\PermissionEnum;
use Uhifadhi\Access\Service\PermissionCatalogueService;

/**
 * The whole declaration chain, through the real container: the uhakiki bundle's
 * provider (tagged uhifadhi.module) declares "verification.tiebreak", the
 * catalogue folds it in beside the app's own PermissionEnum, and — because a
 * module declares but never grants — it carries no capability role. The app
 * itself contains no trace of the permission; uninstall the module and it
 * disappears from this catalogue.
 */
final class PermissionCatalogueWiringTest extends KernelTestCase
{
    public function testTheModuleDeclaredTiebreakPermissionReachesTheCatalogue(): void
    {
        self::bootKernel();
        /** @var PermissionCatalogueService $catalogue */
        $catalogue = static::getContainer()->get(PermissionCatalogueService::class);

        self::assertTrue($catalogue->has('verification.tiebreak'));
        self::assertNull(PermissionEnum::tryFrom('verification.tiebreak'), 'The host itself must not know the permission.');

        $grouped = $catalogue->groupedByUmbrella();
        self::assertArrayHasKey('Verification', $grouped);
        self::assertSame('Tiebreak', $grouped['Verification'][0]->action);
        self::assertNull($grouped['Verification'][0]->capabilityRole, 'Declared, never granted: no role is minted.');
    }
}
