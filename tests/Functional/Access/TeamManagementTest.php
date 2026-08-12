<?php

declare(strict_types=1);

namespace App\Tests\Functional\Access;

use App\Access\Entity\Position;
use App\Access\Entity\User;
use App\Access\Enum\PermissionEnum;
use App\Access\Enum\TeamRoleEnum;
use App\Access\Factory\PositionFactory;
use App\Access\Factory\UserFactory;
use App\Access\Repository\PositionRepository;
use App\Tests\Functional\AuthenticatedWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The /team admin screen end to end: a Manager (team administration is Manager-and-up) creates a
 * position with ticked permissions, assigns it to a Staff member, and deleting a position unassigns
 * its holders. Staff have no access to /team at all.
 */
final class TeamManagementTest extends AuthenticatedWebTestCase
{
    public function testAManagerCreatesAPositionWithTickedPermissions(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);

        $crawler = $client->request('GET', '/team/positions/new');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('input[name="position[_token]"]')->attr('value');
        $client->request('POST', '/team/positions/new', [
            'position' => ['name' => 'Ranger', '_token' => $token],
            'permissions' => [PermissionEnum::AreaView->value, PermissionEnum::IngestionRun->value, 'bogus.perm'],
        ]);

        self::assertResponseRedirects('/team');

        $position = $this->positionByName('Ranger');
        self::assertInstanceOf(Position::class, $position);
        // The unknown value is filtered out; only the two real permissions land.
        self::assertEqualsCanonicalizing(
            [PermissionEnum::AreaView, PermissionEnum::IngestionRun],
            $position->getPermissions(),
        );
    }

    public function testAManagerAssignsAPositionToAStaffMember(): void
    {
        $client = static::createClient();
        $position = PositionFactory::createOne(['name' => 'Ranger']);
        $staff = UserFactory::createOne(['teamRole' => TeamRoleEnum::Staff]);
        $this->loginAs($client, TeamRoleEnum::Manager);

        $crawler = $client->request('GET', '/team');
        $token = $crawler->filter('form[action$="'.$staff->getUuidString().'/assign"] input[name="_token"]')->attr('value');
        $client->request('POST', '/team/members/'.$staff->getUuidString().'/assign', [
            '_token' => $token,
            'position' => $position->getUuidString(),
        ]);

        self::assertResponseRedirects('/team');
        self::assertSame('Ranger', $this->reload($staff)->getPosition()?->getName());
    }

    public function testDeletingAPositionUnassignsItsHolders(): void
    {
        $client = static::createClient();
        $position = PositionFactory::createOne(['name' => 'Temporary']);
        $staff = UserFactory::createOne(['teamRole' => TeamRoleEnum::Staff, 'position' => $position]);
        $this->loginAs($client, TeamRoleEnum::Manager);

        $crawler = $client->request('GET', '/team');
        $token = $crawler->filter('form[action$="'.$position->getUuidString().'/delete"] input[name="_token"]')->attr('value');
        $client->request('POST', '/team/positions/'.$position->getUuidString().'/delete', ['_token' => $token]);

        self::assertResponseRedirects('/team');
        self::assertNull($this->positionByName('Temporary'));
        self::assertNull($this->reload($staff)->getPosition(), 'the holder must be unassigned, not orphaned');
    }

    public function testStaffHaveNoAccessToTheTeamScreen(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        $client->request('GET', '/team');

        self::assertResponseStatusCodeSame(403);
    }

    private function positionByName(string $name): ?Position
    {
        $repo = static::getContainer()->get(PositionRepository::class);
        \assert($repo instanceof PositionRepository);

        return $repo->findOneBy(['name' => $name]);
    }

    private function reload(User $user): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($user->getId());
        \assert($reloaded instanceof User);

        return $reloaded;
    }
}
