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

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;
use Twig\TwigFunction;
use Uhifadhi\Entity\Department;
use Uhifadhi\Factory\DepartmentFactory;
use Uhifadhi\Factory\ModuleFactory;
use Zenstruck\Foundry\Test\Factories;

/**
 * The Departments `registry` widget: the table of departments with the modules they claim, the
 * positions filed under them and the members those positions carry — plus, for someone who may
 * administer departments, the inline create/rename/delete controls.
 *
 * The widget is rendered through a Twig harness rather than through a page: the /departments
 * screen and its endpoints are being built alongside this partial, so the harness pins the
 * contract the partial is written against (route names, URLs and the shared `department_manage`
 * CSRF token id) instead of waiting on the router. Swap the harness for a page request once the
 * screen exists — the assertions below hold either way.
 */
final class DepartmentRegistryWidgetTest extends KernelTestCase
{
    use Factories;

    private const TEMPLATE = 'departments/_w_registry.html.twig';

    public function testItListsEveryDepartmentWithItsModulesAndCounts(): void
    {
        self::bootKernel();
        $patrols = ModuleFactory::createOne(['name' => 'Patrols']);
        $incidents = ModuleFactory::createOne(['name' => 'Incidents']);
        $protection = DepartmentFactory::createOne(['name' => 'Protection Service', 'modules' => [$patrols, $incidents]]);
        $ecology = DepartmentFactory::createOne(['name' => 'Ecology', 'modules' => [$incidents]]);
        $humanResource = DepartmentFactory::createOne(['name' => 'Human Resource', 'modules' => []]);

        $crawler = $this->render([
            'departments' => [$protection, $ecology, $humanResource],
            'modules' => [$patrols, $incidents],
            'departmentsByModule' => [
                (int) $patrols->getId() => [$protection],
                (int) $incidents->getId() => [$protection, $ecology],
            ],
            'positionsByDepartment' => [(int) $protection->getId() => 3, (int) $ecology->getId() => 2],
            'usersByDepartment' => [(int) $protection->getId() => 42, (int) $ecology->getId() => 14],
            'canManage' => false,
        ]);

        $rows = $crawler->filter('table.tbl tbody tr');
        self::assertCount(3, $rows, 'one row per department, and no create row for a reader');

        $first = $rows->eq(0);
        self::assertStringContainsString('Protection Service', $first->text());
        self::assertSame(['Patrols', 'Incidents·2'], $first->filter('.dchip')->each(
            static fn (Crawler $chip): string => preg_replace('/\s+/', '', $chip->text()) ?? '',
        ));
        // A module two departments claim is marked shared; one claimed alone is not.
        self::assertStringNotContainsString('shared', (string) $first->filter('.dchip')->eq(0)->attr('class'));
        self::assertStringContainsString('shared', (string) $first->filter('.dchip')->eq(1)->attr('class'));
        self::assertSame('3', trim($first->filter('td.num')->eq(0)->text()));
        self::assertSame('42', trim($first->filter('td.num')->eq(1)->text()));

        // A department claiming nothing says so rather than showing an empty cell.
        self::assertSame('none', trim($rows->eq(2)->filter('.dchip.ghost')->text()));
        self::assertSame('0', trim($rows->eq(2)->filter('td.num')->eq(0)->text()));
    }

    public function testAReaderGetsNoManagementControls(): void
    {
        self::bootKernel();
        $department = DepartmentFactory::createOne(['name' => 'Ecology']);

        $crawler = $this->render($this->context($department, canManage: false));

        self::assertCount(0, $crawler->filter('form'));
        self::assertCount(0, $crawler->filter('input'));
        self::assertCount(0, $crawler->filter('button'));
        self::assertStringContainsString('Ecology', $crawler->text());
    }

    public function testAManagerGetsTheInlineCreateRow(): void
    {
        self::bootKernel();
        $department = DepartmentFactory::createOne(['name' => 'Ecology']);

        $crawler = $this->render($this->context($department, canManage: true));

        $create = $crawler->filter('form[action="/departments"]');
        self::assertCount(1, $create, 'the create row posts to the pinned POST /departments endpoint');
        self::assertCount(1, $create->filter('input[name="name"]'));
        self::assertSame('csrf:department_manage', $create->filter('input[name="_token"]')->attr('value'));
        // The submit may sit in another cell, wired by the form attribute.
        self::assertCount(1, $crawler->filter('button[form="'.$create->attr('id').'"], form[action="/departments"] button'));
    }

    public function testAManagerRenamesADepartmentInline(): void
    {
        self::bootKernel();
        $department = DepartmentFactory::createOne(['name' => 'Ecology']);
        $uuid = $department->getUuidString();

        $crawler = $this->render($this->context($department, canManage: true));

        $rename = $crawler->filter('form[action="/departments/'.$uuid.'/rename"]');
        self::assertCount(1, $rename);
        self::assertSame('Ecology', $rename->filter('input[name="name"]')->attr('value'));
        self::assertSame('csrf:department_manage', $rename->filter('input[name="_token"]')->attr('value'));
    }

    public function testDeleteGoesThroughTheHostConfirmModal(): void
    {
        self::bootKernel();
        $department = DepartmentFactory::createOne(['name' => 'Ecology']);
        $uuid = $department->getUuidString();

        $crawler = $this->render($this->context($department, canManage: true));

        $delete = $crawler->filter('form[action="/departments/'.$uuid.'/delete"]');
        self::assertCount(1, $delete);
        self::assertSame('csrf:department_manage', $delete->filter('input[name="_token"]')->attr('value'));

        $button = $delete->filter('button');
        self::assertSame('confirm-modal', $button->attr('data-controller'));
        self::assertSame('click->confirm-modal#ask', $button->attr('data-action'));
        self::assertSame('true', $button->attr('data-confirm-modal-danger-value'));
        self::assertStringContainsString('danger', (string) $button->attr('class'));

        $message = (string) $button->attr('data-confirm-modal-message-value');
        self::assertStringContainsString('module', $message, 'the message must say the modules survive');
        self::assertStringContainsString('unfiled', $message, 'and that the positions are unfiled');
    }

    /**
     * The harness above stubs `path()`, so this is what keeps the stub honest: once the
     * /departments screen registers its endpoints, they must be exactly the URLs the widget
     * was written against. Skipped while the routes are still being built.
     */
    public function testThePinnedRoutesAreTheRealOnes(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get('router');
        \assert($router instanceof RouterInterface);
        $routes = $router->getRouteCollection();

        if (null === $routes->get('app_department_create')) {
            self::markTestSkipped('The /departments endpoints are not registered yet — the harness pins the contract meanwhile.');
        }

        self::assertSame('/departments', $routes->get('app_department_create')->getPath());
        self::assertSame('/departments/{uuid}/rename', $routes->get('app_department_rename')?->getPath());
        self::assertSame('/departments/{uuid}/delete', $routes->get('app_department_delete')?->getPath());
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Department $department, bool $canManage): array
    {
        return [
            'departments' => [$department],
            'modules' => [],
            'departmentsByModule' => [],
            // Lists, not counts: the partial must render either shape the page hands it.
            'positionsByDepartment' => [(int) $department->getId() => []],
            'usersByDepartment' => [(int) $department->getId() => []],
            'canManage' => $canManage,
        ];
    }

    /**
     * Render the partial with the page's contract stubbed: the pinned department URLs, a
     * recognisable CSRF token, and the icon helper.
     *
     * @param array<string, mixed> $context
     */
    private function render(array $context): Crawler
    {
        $twig = self::getContainer()->get('twig');
        \assert($twig instanceof Environment);

        $harness = new Environment($twig->getLoader(), ['strict_variables' => true]);
        $harness->addFunction(new TwigFunction('path', static function (string $name, array $parameters = []): string {
            $uuid = \is_string($parameters['uuid'] ?? null) ? $parameters['uuid'] : '';

            return match ($name) {
                'app_department_create' => '/departments',
                'app_department_rename' => '/departments/'.$uuid.'/rename',
                'app_department_delete' => '/departments/'.$uuid.'/delete',
                default => self::fail(\sprintf('The registry widget must only use the pinned department routes, got "%s".', $name)),
            };
        }));
        $harness->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => 'csrf:'.$id));
        $harness->addFunction(new TwigFunction(
            'ux_icon',
            static fn (string $name): string => '<svg data-icon="'.$name.'"></svg>',
            ['is_safe' => ['html']],
        ));

        return new Crawler($harness->render(self::TEMPLATE, $context));
    }
}
