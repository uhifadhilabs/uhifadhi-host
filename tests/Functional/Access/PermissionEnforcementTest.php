<?php

declare(strict_types=1);

namespace App\Tests\Functional\Access;

use App\Access\Enum\PermissionEnum;
use App\Access\Enum\TeamRoleEnum;
use App\Access\Factory\PositionFactory;
use App\Access\Factory\UserFactory;
use App\Spatial\Factory\AreaOfInterestFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The #[IsGranted] guards on the dashboard actions, exercised through a real login: a
 * Staff user is limited to their Position's permissions (403 otherwise), while a managing
 * tier holds everything. This is the enforcement counterpart to the unit-level
 * {@see \App\Tests\Unit\Access\Security\PermissionVoterTest}.
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

    public function testStaffWithoutIngestionRunCannotTriggerIngestion(): void
    {
        $client = static::createClient();
        $this->loginStaffWith($client, [PermissionEnum::AreaView]);
        $area = AreaOfInterestFactory::createOne();

        // The detail page itself is ROLE_USER, so they can open it and see the form…
        $client->request('GET', '/areas/'.$area->getUuidString());
        self::assertResponseIsSuccessful();

        // …but the guard on the ingest action refuses the POST before it runs.
        $client->submitForm('Run Hansen forest-loss ingestion');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAManagerIsPositionDrivenForIngestion(): void
    {
        // Without a position, a Manager holds nothing — the tier grants no permissions.
        $client = static::createClient();
        $client->loginUser(UserFactory::createOne(['teamRole' => TeamRoleEnum::Manager]));
        $area = AreaOfInterestFactory::createOne();

        $client->request('GET', '/areas/'.$area->getUuidString());
        $client->submitForm('Run Hansen forest-loss ingestion');
        self::assertResponseStatusCodeSame(403);

        // With a position granting ingestion.run, the same action goes through.
        $client->loginUser(UserFactory::createOne([
            'teamRole' => TeamRoleEnum::Manager,
            'position' => PositionFactory::new()->withPermissions([PermissionEnum::IngestionRun])->create(),
        ]));
        $client->request('GET', '/areas/'.$area->getUuidString());
        $client->submitForm('Run Hansen forest-loss ingestion');
        self::assertResponseRedirects('/areas/'.$area->getUuidString());
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
