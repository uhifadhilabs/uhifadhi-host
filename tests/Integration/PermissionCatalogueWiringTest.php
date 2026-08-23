<?php

declare(strict_types=1);

/*
 * This file is part of uhifadhi.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Uhifadhi\Enum\PermissionEnum;
use Uhifadhi\Service\PermissionCatalogueService;

/**
 * The whole declaration chain, through the real container: the patrol module's
 * provider (tagged uhifadhi.module) declares "patrols.record", the
 * catalogue folds it in beside the app's own PermissionEnum, and — because a
 * module declares but never grants — it carries no capability role. The app
 * itself contains no trace of the permission; uninstall the module and it
 * disappears from this catalogue.
 */
final class PermissionCatalogueWiringTest extends KernelTestCase
{
    public function testAModuleDeclaredPermissionReachesTheCatalogue(): void
    {
        self::bootKernel();
        /** @var PermissionCatalogueService $catalogue */
        $catalogue = static::getContainer()->get(PermissionCatalogueService::class);

        self::assertTrue($catalogue->has('patrols.record'));
        self::assertNull(PermissionEnum::tryFrom('patrols.record'), 'The host itself must not know the permission.');

        $grouped = $catalogue->groupedByUmbrella();
        self::assertArrayHasKey('Patrols', $grouped);
        self::assertSame('Record', $grouped['Patrols'][0]->action);
        self::assertNull($grouped['Patrols'][0]->capabilityRole, 'Declared, never granted: no role is minted.');
    }
}
