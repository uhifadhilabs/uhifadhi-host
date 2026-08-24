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
use Uhifadhi\Entity\Department;
use Uhifadhi\Enum\TeamRoleEnum;
use Uhifadhi\Factory\DepartmentFactory;
use Uhifadhi\Factory\ModuleFactory;
use Uhifadhi\Repository\DepartmentRepository;

/**
 * Creating, renaming, deleting a department and attaching a module to it. Administering the
 * org chart is Manager-and-up, exactly as /team is; every write carries the one CSRF token id
 * ('department_manage') the management forms render.
 */
final class DepartmentManagementTest extends AuthenticatedWebTestCase
{
    public function testAManagerCreatesADepartment(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);

        $client->request('POST', '/departments', ['_token' => $this->token($client), 'name' => 'Ecology']);

        self::assertResponseRedirects('/departments');
        self::assertNotNull($this->find('Ecology'));
    }

    public function testCreatingWithoutANameChangesNothing(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);

        $client->request('POST', '/departments', ['_token' => $this->token($client), 'name' => '  ']);

        self::assertResponseRedirects('/departments');
        self::assertSame([], self::repository()->findAllOrdered());
    }

    public function testAManagerRenamesADepartment(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);
        $department = DepartmentFactory::createOne(['name' => 'Ecolgy']);

        $client->request('POST', '/departments/'.$department->getUuidString().'/rename', [
            '_token' => $this->token($client),
            'name' => 'Ecology',
        ]);

        self::assertResponseRedirects('/departments');
        self::assertNotNull($this->find('Ecology'));
        self::assertNull($this->find('Ecolgy'));
    }

    public function testAManagerDeletesADepartment(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);
        $department = DepartmentFactory::createOne(['name' => 'Tourism']);

        $client->request('POST', '/departments/'.$department->getUuidString().'/delete', ['_token' => $this->token($client)]);

        self::assertResponseRedirects('/departments');
        self::assertNull($this->find('Tourism'));
    }

    public function testTogglingAttachesThenDetachesAModule(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);
        $department = DepartmentFactory::createOne(['name' => 'Protection']);
        $module = ModuleFactory::createOne(['name' => 'Patrols']);
        $url = '/departments/'.$department->getUuidString().'/modules/'.$module->getUuidString().'/toggle';
        $token = $this->token($client);

        $client->request('POST', $url, ['_token' => $token]);
        self::assertResponseRedirects('/departments');
        self::assertSame(1, $this->find('Protection')?->getModules()->count(), 'the module is attached');

        $client->request('POST', $url, ['_token' => $token]);
        self::assertResponseRedirects('/departments');
        self::assertSame(0, $this->find('Protection')?->getModules()->count(), 'and detached again');
    }

    public function testStaffMayNotAdministerDepartments(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);
        $department = DepartmentFactory::createOne(['name' => 'Ecology']);
        $uuid = $department->getUuidString();
        $module = ModuleFactory::createOne();

        foreach ([
            '/departments' => ['name' => 'Finance'],
            '/departments/'.$uuid.'/rename' => ['name' => 'Nope'],
            '/departments/'.$uuid.'/delete' => [],
            '/departments/'.$uuid.'/modules/'.$module->getUuidString().'/toggle' => [],
        ] as $url => $payload) {
            $client->request('POST', $url, $payload);
            self::assertResponseStatusCodeSame(403, $url.' is Manager-and-up');
        }

        self::assertNotNull($this->find('Ecology'));
        self::assertNull($this->find('Finance'));
    }

    public function testTheDashboardOffersNoManagementToStaff(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);

        $client->request('GET', '/departments');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action="/departments"]');
    }

    public function testAWriteWithoutTheTokenIsRefused(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);

        $client->request('POST', '/departments', ['name' => 'Ecology']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], self::repository()->findAllOrdered());
    }

    public function testAnonymousVisitorsMayNotCreate(): void
    {
        $client = static::createClient();

        $client->request('POST', '/departments', ['name' => 'Ecology']);

        self::assertResponseRedirects('http://localhost/login');
    }

    /**
     * The token the management chrome itself rendered. One id serves every management
     * write, so the create form's token is the token.
     */
    private function token(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/departments');

        return (string) $crawler->filter('form[action="/departments"] input[name="_token"]')->attr('value');
    }

    private function find(string $name): ?Department
    {
        return self::repository()->findOneBy(['name' => $name]);
    }

    private static function repository(): DepartmentRepository
    {
        /** @var DepartmentRepository $repository */
        $repository = static::getContainer()->get(DepartmentRepository::class);

        return $repository;
    }
}
