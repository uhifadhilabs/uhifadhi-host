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
 * The writes behind /team, end to end: creating a position IN A DEPARTMENT, renaming it,
 * deleting it, filing an unfiled one, and assigning one to a Staff member.
 *
 * The through-line is that THE DEPARTMENT IS ALWAYS EXPLICIT. A position's name is unique inside
 * its department only, so there is no create path that does not name one and no inline control
 * that quietly moves a position between two — the old "Set department" select is gone, and the
 * one move that remains is out of the unfiled holding pen.
 */
final class TeamManagementTest extends AuthenticatedWebTestCase
{
    public function testAManagerCreatesAPositionWithTickedPermissionsInsideADepartment(): void
    {
        $client = static::createClient();
        $ecology = DepartmentFactory::createOne(['name' => 'Ecology']);
        $this->loginAs($client, TeamRoleEnum::Manager);

        $crawler = $client->request('GET', '/team/positions/new');
        self::assertResponseIsSuccessful();

        // Department is field ONE of the screen, and it is required.
        self::assertNotNull($crawler->filter('select[name="department"]')->attr('required'));

        $token = $crawler->filter('input[name="position[_token]"]')->attr('value');
        $client->request('POST', '/team/positions/new', [
            'position' => ['name' => 'Ranger', '_token' => $token],
            'department' => $ecology->getUuidString(),
            'permissions' => [PermissionEnum::AreaView->value, PermissionEnum::IngestionRun->value, 'bogus.perm'],
        ]);

        self::assertResponseRedirects('/team');

        $position = $this->positionByName('Ranger');
        self::assertInstanceOf(Position::class, $position);
        self::assertSame('Ecology', $position->getDepartment()?->getName());
        // The unknown value is filtered out; only the two real permissions land.
        self::assertEqualsCanonicalizing(
            [PermissionEnum::AreaView, PermissionEnum::IngestionRun],
            $position->getPermissions(),
        );
    }

    public function testABandsCreateRowCarriesItsOwnDepartment(): void
    {
        $client = static::createClient();
        $ecology = DepartmentFactory::createOne(['name' => 'Ecology']);
        $this->loginAs($client, TeamRoleEnum::Manager);

        // The affordance the default direction ships: a create row inside a department band. Its
        // department is a hidden field, so it is decided by where the row sits.
        $crawler = $client->request('GET', '/team');
        $token = $crawler->filter('tr.newrow input[name="_token"]')->first()->attr('value');

        $client->request('POST', '/team/positions', [
            '_token' => $token,
            'department' => $ecology->getUuidString(),
            'name' => 'Field Officer',
        ]);

        self::assertResponseRedirects('/team');
        self::assertSame('Ecology', $this->positionByName('Field Officer')?->getDepartment()?->getName());
    }

    public function testTwoDepartmentsMayEachCreateAPositionCalledAnalyst(): void
    {
        $client = static::createClient();
        $ecology = DepartmentFactory::createOne(['name' => 'Ecology']);
        $protection = DepartmentFactory::createOne(['name' => 'Protection Service']);
        $this->loginAs($client, TeamRoleEnum::Manager);

        $crawler = $client->request('GET', '/team');
        $token = $crawler->filter('tr.newrow input[name="_token"]')->first()->attr('value');

        foreach ([$ecology, $protection] as $department) {
            $client->request('POST', '/team/positions', [
                '_token' => $token,
                'department' => $department->getUuidString(),
                'name' => 'Analyst',
            ]);
            self::assertResponseRedirects('/team');
        }

        // Two rows, two departments, one word — which is the whole ruling.
        self::assertCount(2, $this->positions()->findBy(['name' => 'Analyst']));
    }

    public function testTheSameDepartmentIsRefusedTheSameNameTwiceInItsOwnWords(): void
    {
        $client = static::createClient();
        $ecology = DepartmentFactory::createOne(['name' => 'Ecology']);
        PositionFactory::createOne(['name' => 'Analyst', 'department' => $ecology]);
        $this->loginAs($client, TeamRoleEnum::Manager);

        $crawler = $client->request('GET', '/team');
        $token = $crawler->filter('tr.newrow input[name="_token"]')->first()->attr('value');

        $client->request('POST', '/team/positions', [
            '_token' => $token,
            'department' => $ecology->getUuidString(),
            'name' => 'Analyst',
        ]);

        // A refusal on the screen, in the rule's words — never a 500 from the index behind it.
        self::assertResponseRedirects('/team');
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Ecology already has a position called', $crawler->filter('.tm-flash')->text());
        self::assertCount(1, $this->positions()->findBy(['name' => 'Analyst']));
    }

    public function testThereIsNoInlineControlThatMovesAFiledPositionBetweenDepartments(): void
    {
        $client = static::createClient();
        $ecology = DepartmentFactory::createOne(['name' => 'Ecology']);
        $filed = PositionFactory::createOne(['name' => 'Ecologist', 'department' => $ecology]);
        $this->loginAs($client, TeamRoleEnum::Manager);

        $crawler = $client->request('GET', '/team');
        self::assertResponseIsSuccessful();

        // The old "Set department" select on every position row is gone: it re-scoped a name
        // against a set of names nobody was looking at.
        self::assertCount(0, $crawler->filter('form[action$="'.$filed->getUuidString().'/department"]'));

        // And the route refuses it too, not only the template.
        $token = $crawler->filter('tr.newrow input[name="_token"]')->first()->attr('value');
        $other = DepartmentFactory::createOne(['name' => 'Protection Service']);
        $client->request('POST', '/team/positions/'.$filed->getUuidString().'/department', [
            '_token' => $token,
            'department' => $other->getUuidString(),
        ]);
        self::assertResponseRedirects('/team');
        self::assertSame('Ecology', $this->positionByName('Ecologist')?->getDepartment()?->getName());
    }

    public function testAnUnfiledPositionIsFiledUnderARealDepartmentAndItsHoldersMoveWithIt(): void
    {
        $client = static::createClient();
        $ecology = DepartmentFactory::createOne(['name' => 'Ecology']);
        $unfiled = PositionFactory::createOne(['name' => 'Park Manager', 'department' => null]);
        $holder = UserFactory::createOne(['teamRole' => TeamRoleEnum::Staff, 'position' => $unfiled]);
        $this->loginAs($client, TeamRoleEnum::Manager);

        $crawler = $client->request('GET', '/team');
        // The holding pen's one real action, on the row itself.
        $form = $crawler->filter('form[action$="'.$unfiled->getUuidString().'/department"]');
        self::assertCount(1, $form);

        $client->request('POST', '/team/positions/'.$unfiled->getUuidString().'/department', [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
            'department' => $ecology->getUuidString(),
        ]);

        self::assertResponseRedirects('/team');
        self::assertSame('Ecology', $this->positionByName('Park Manager')?->getDepartment()?->getName());
        // Membership is indirect, so the holder moved without anyone touching their row.
        self::assertSame('Ecology', $this->reload($holder)->getPosition()?->getDepartment()?->getName());
    }

    public function testAManagerAssignsAPositionToAStaffMember(): void
    {
        $client = static::createClient();
        $ecology = DepartmentFactory::createOne(['name' => 'Ecology']);
        $position = PositionFactory::createOne(['name' => 'Ranger', 'department' => $ecology]);
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

    public function testRenamingIsCheckedAgainstItsOwnDepartmentOnly(): void
    {
        $client = static::createClient();
        $ecology = DepartmentFactory::createOne(['name' => 'Ecology']);
        $protection = DepartmentFactory::createOne(['name' => 'Protection Service']);
        PositionFactory::createOne(['name' => 'Analyst', 'department' => $ecology]);
        $ranger = PositionFactory::createOne(['name' => 'Ranger', 'department' => $protection]);
        $this->loginAs($client, TeamRoleEnum::Manager);

        $crawler = $client->request('GET', '/team/positions/'.$ranger->getUuidString().'/edit');
        self::assertResponseIsSuccessful();
        // The edit screen states the department AS A SELECT, preselected on the one it holds:
        // this is the screen that owns a position whole, so it is where its department is set.
        $selected = $crawler->filter('select[name="department"] option[selected]');
        self::assertCount(1, $selected);
        self::assertSame((string) $protection->getUuidString(), $selected->attr('value'));

        // Ecology's Analyst is no obstacle: the check runs against Protection Service alone.
        $token = $crawler->filter('input[name="position[_token]"]')->attr('value');
        $client->request('POST', '/team/positions/'.$ranger->getUuidString().'/edit', [
            'position' => ['name' => 'Analyst', '_token' => $token],
            'department' => $protection->getUuidString(),
            'permissions' => [PermissionEnum::AreaView->value],
        ]);

        self::assertResponseRedirects('/team');
        self::assertCount(2, $this->positions()->findBy(['name' => 'Analyst']));
    }

    /**
     * THE EDIT SCREEN IS WHERE A POSITION'S DEPARTMENT IS SET.
     *
     * It used to state the department read-only and send the reader to "the Positions widget" —
     * which only ever offered the move for UNFILED positions, so for a filed one the sentence
     * named a control that does not exist. The screen that owns the position owns its department.
     */
    public function testTheEditScreenMovesAPositionToAnotherDepartmentAndItsHoldersMoveWithIt(): void
    {
        $client = static::createClient();
        $ecology = DepartmentFactory::createOne(['name' => 'Ecology']);
        $protection = DepartmentFactory::createOne(['name' => 'Protection Service']);
        $ranger = PositionFactory::createOne(['name' => 'Ranger', 'department' => $ecology]);
        $holder = UserFactory::createOne(['teamRole' => TeamRoleEnum::Staff, 'position' => $ranger]);
        $this->loginAs($client, TeamRoleEnum::Manager);

        $crawler = $client->request('GET', '/team/positions/'.$ranger->getUuidString().'/edit');
        // The dead-end sentence is gone: there is a control here, so nothing points elsewhere.
        self::assertStringNotContainsString('from the Positions widget', (string) $client->getResponse()->getContent());

        $client->request('POST', '/team/positions/'.$ranger->getUuidString().'/edit', [
            'position' => ['name' => 'Ranger', '_token' => $crawler->filter('input[name="position[_token]"]')->attr('value')],
            'department' => $protection->getUuidString(),
            'permissions' => [PermissionEnum::AreaView->value],
        ]);

        self::assertResponseRedirects('/team');
        self::assertSame('Protection Service', $this->positionByName('Ranger')?->getDepartment()?->getName());
        // Membership is indirect: the holder followed without their row being touched.
        self::assertSame('Protection Service', $this->reload($holder)->getPosition()?->getDepartment()?->getName());
    }

    public function testAMoveIntoADepartmentThatAlreadyHasTheNameIsRefusedInWords(): void
    {
        $client = static::createClient();
        $ecology = DepartmentFactory::createOne(['name' => 'Ecology']);
        $protection = DepartmentFactory::createOne(['name' => 'Protection Service']);
        $analyst = PositionFactory::createOne(['name' => 'Analyst', 'department' => $ecology]);
        PositionFactory::createOne(['name' => 'Analyst', 'department' => $protection]);
        $this->loginAs($client, TeamRoleEnum::Manager);

        $crawler = $client->request('GET', '/team/positions/'.$analyst->getUuidString().'/edit');
        $crawler = $client->request('POST', '/team/positions/'.$analyst->getUuidString().'/edit', [
            'position' => ['name' => 'Analyst', '_token' => $crawler->filter('input[name="position[_token]"]')->attr('value')],
            'department' => $protection->getUuidString(),
            'permissions' => [PermissionEnum::AreaView->value],
        ]);

        // Refused as a sentence on the screen, not a 500 from the unique index behind it.
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Analyst', $crawler->filter('.tm-flash')->text());
        self::assertSame('Ecology', $this->positionByName('Analyst')?->getDepartment()?->getName());
    }

    /**
     * A position may also be sent BACK to the holding pen — the select's first option is Unfiled,
     * and it is a real answer, not a placeholder.
     */
    public function testTheEditScreenCanUnfileAPosition(): void
    {
        $client = static::createClient();
        $ecology = DepartmentFactory::createOne(['name' => 'Ecology']);
        $ranger = PositionFactory::createOne(['name' => 'Ranger', 'department' => $ecology]);
        $this->loginAs($client, TeamRoleEnum::Manager);

        $crawler = $client->request('GET', '/team/positions/'.$ranger->getUuidString().'/edit');
        $client->request('POST', '/team/positions/'.$ranger->getUuidString().'/edit', [
            'position' => ['name' => 'Ranger', '_token' => $crawler->filter('input[name="position[_token]"]')->attr('value')],
            'department' => 'unfiled',
            'permissions' => [PermissionEnum::AreaView->value],
        ]);

        self::assertResponseRedirects('/team');
        self::assertNull($this->positionByName('Ranger')?->getDepartment());
    }

    public function testStaffHaveNoAccessToTheTeamScreen(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        $client->request('GET', '/team');

        self::assertResponseStatusCodeSame(403);
    }

    private function positions(): PositionRepository
    {
        $repo = static::getContainer()->get(PositionRepository::class);
        \assert($repo instanceof PositionRepository);

        return $repo;
    }

    private function positionByName(string $name): ?Position
    {
        return $this->positions()->findOneBy(['name' => $name]);
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
