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

namespace Uhifadhi\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Uhifadhi\Enum\PermissionEnum;
use Uhifadhi\Enum\TeamRoleEnum;
use Uhifadhi\Factory\AreaOfInterestFactory;
use Uhifadhi\Factory\PositionFactory;
use Uhifadhi\Factory\UserFactory;
use Zenstruck\Foundry\Test\Factories;

/**
 * The #[IsGranted] guards on the dashboard actions, exercised through a real login: a
 * Staff user is limited to their Position's permissions (403 otherwise), while a managing
 * tier holds everything. This is the enforcement counterpart to the unit-level
 * {@see \Uhifadhi\Tests\Unit\PermissionVoterTest}.
 */
final class PermissionEnforcementTest extends WebTestCase
{
    use Factories;

    public function testStaffWithoutAreaCreateAreForbiddenFromTheUploadPage(): void
    {
        $client = static::createClient();
        $this->loginStaffWith($client, [PermissionEnum::AreaView]);

        $client->request('GET', '/areas/new');

        self::assertResponseStatusCodeSame(403);
    }

    public function testStaffWithAreaCreateReachTheUploadPage(): void
    {
        $client = static::createClient();
        $this->loginStaffWith($client, [PermissionEnum::AreaCreate]);

        $client->request('GET', '/areas/new');

        self::assertResponseIsSuccessful();
    }

    public function testStaffWithoutAreaEditCannotOpenAreaSettings(): void
    {
        $client = static::createClient();
        $this->loginStaffWith($client, [PermissionEnum::AreaView]);
        $area = AreaOfInterestFactory::createOne();

        // The detail page itself is ROLE_USER, so they can open it…
        $client->request('GET', '/areas/'.$area->getUuidString());
        self::assertResponseIsSuccessful();

        // …but the guard on the settings page refuses before it renders.
        $client->request('GET', '/areas/'.$area->getUuidString().'/settings');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAManagerIsPositionDrivenForAreaSettings(): void
    {
        // Without a position, a Manager holds nothing — the tier grants no permissions.
        $client = static::createClient();
        $client->loginUser(UserFactory::createOne(['teamRole' => TeamRoleEnum::Manager]));
        $area = AreaOfInterestFactory::createOne();

        $client->request('GET', '/areas/'.$area->getUuidString().'/settings');
        self::assertResponseStatusCodeSame(403);

        // With a position granting area.edit, the same page opens.
        $client->loginUser(UserFactory::createOne([
            'teamRole' => TeamRoleEnum::Manager,
            'position' => PositionFactory::new()->withPermissions([PermissionEnum::AreaEdit])->create(),
        ]));
        $client->request('GET', '/areas/'.$area->getUuidString().'/settings');
        self::assertResponseIsSuccessful();
    }

    public function testComposingModulesIsReservedForTheAdminTier(): void
    {
        $client = static::createClient();
        $area = AreaOfInterestFactory::createOne();
        $customize = '/areas/'.$area->getUuidString().'/modules/customize';

        // Even a fully-permissioned Manager position cannot compose modules…
        $client->loginUser(UserFactory::createOne([
            'teamRole' => TeamRoleEnum::Manager,
            'position' => PositionFactory::new()->withPermissions(PermissionEnum::cases())->create(),
        ]));
        $client->request('GET', $customize);
        self::assertResponseStatusCodeSame(403);

        // …nor can Staff holding module.create (that capability is settings/viz, not composition)…
        $this->loginStaffWith($client, [PermissionEnum::ModuleView, PermissionEnum::ModuleCreate]);
        $client->request('GET', $customize);
        self::assertResponseStatusCodeSame(403);

        // …while an Admin composes without any position at all.
        $client->loginUser(UserFactory::createOne(['teamRole' => TeamRoleEnum::Admin]));
        $client->request('GET', $customize);
        self::assertResponseIsSuccessful();
    }

    /**
     * @param list<PermissionEnum> $permissions
     */
    private function loginStaffWith(KernelBrowser $client, array $permissions): void
    {
        $position = PositionFactory::new()->withPermissions($permissions)->create();
        $client->loginUser(UserFactory::createOne([
            'teamRole' => TeamRoleEnum::Staff,
            'position' => $position,
        ]));
    }
}
