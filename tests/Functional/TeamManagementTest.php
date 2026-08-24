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

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Entity\Position;
use Uhifadhi\Entity\User;
use Uhifadhi\Enum\PermissionEnum;
use Uhifadhi\Enum\TeamRoleEnum;
use Uhifadhi\Factory\DepartmentFactory;
use Uhifadhi\Factory\PositionFactory;
use Uhifadhi\Factory\UserFactory;
use Uhifadhi\Repository\PositionRepository;

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

    public function testThePositionsTableCarriesADepartmentColumn(): void
    {
        $client = static::createClient();
        $ecology = DepartmentFactory::createOne(['name' => 'Ecology']);
        $filed = PositionFactory::createOne(['name' => 'Ecologist', 'department' => $ecology]);
        PositionFactory::createOne(['name' => 'Drifter', 'department' => null]);
        UserFactory::createOne(['teamRole' => TeamRoleEnum::Staff, 'position' => $filed]);
        $this->loginAs($client, TeamRoleEnum::Manager);

        $crawler = $client->request('GET', '/team');
        self::assertResponseIsSuccessful();

        // The position's own department: a select of the org-wide list, the current one chosen.
        $select = $crawler->filter('form[action$="'.$filed->getUuidString().'/department"] select[name="department"]');
        self::assertCount(1, $select);
        self::assertSame('Ecology', $select->filter('option[selected]')->text());
        self::assertStringContainsString('— none —', $select->text());

        // A person's department is read-only and comes from their position.
        $person = $crawler->filter('table.tbl tbody tr:contains("Ecologist")');
        self::assertStringContainsString('via position', $crawler->filter('.dept-via')->text());
        self::assertGreaterThan(0, $person->count());
    }

    public function testAManagerFilesAPositionUnderADepartmentAndUnfilesItAgain(): void
    {
        $client = static::createClient();
        $ecology = DepartmentFactory::createOne(['name' => 'Ecology']);
        $position = PositionFactory::createOne(['name' => 'Ecologist']);
        $this->loginAs($client, TeamRoleEnum::Manager);

        $crawler = $client->request('GET', '/team');
        $token = $crawler->filter('form[action$="'.$position->getUuidString().'/department"] input[name="_token"]')->attr('value');

        $client->request('POST', '/team/positions/'.$position->getUuidString().'/department', [
            '_token' => $token,
            'department' => $ecology->getUuidString(),
        ]);
        self::assertResponseRedirects('/team');
        self::assertSame('Ecology', $this->positionByName('Ecologist')?->getDepartment()?->getName());

        // The empty option unfiles the position — the department itself is untouched.
        $client->request('POST', '/team/positions/'.$position->getUuidString().'/department', [
            '_token' => $token,
            'department' => '',
        ]);
        self::assertResponseRedirects('/team');
        self::assertNull($this->positionByName('Ecologist')?->getDepartment());
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
