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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;
use Uhifadhi\Entity\Department;
use Uhifadhi\Entity\Module;
use Uhifadhi\Enum\TeamRoleEnum;
use Uhifadhi\Factory\DepartmentFactory;
use Uhifadhi\Factory\ModuleFactory;
use Uhifadhi\Model\WidgetDom;
use Uhifadhi\Repository\DepartmentRepository;

/**
 * The departments 'matrix' widget: the whole org in one grid — departments down, every module
 * across, a dot at each intersection. What is asserted here is the reading (filled vs empty dots,
 * the tint on a column two departments claim, the per-column counts) and the fact that with the
 * permission every dot is a real form control while without it the same grid is inert.
 *
 * Rendered through a Twig harness rather than a request: the /departments page is the core
 * agent's, and this widget must be provable on its own contract — the six variables it is handed.
 */
final class DepartmentMatrixWidgetTest extends AuthenticatedWebTestCase
{
    private const string TEMPLATE = 'departments/_w_matrix.html.twig';

    private KernelBrowser $client;

    /**
     * The client is booted first because the factories below persist through the same kernel —
     * Foundry would otherwise boot one of its own and {@see static::createClient()} refuses a
     * second.
     */
    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testAManagerGetsATogglingFormAtEveryIntersection(): void
    {
        [$departments, $modules] = $this->fixture();
        $crawler = $this->renderAs(TeamRoleEnum::Manager, $departments, $modules);

        // Two departments × three modules — every cell carries its own one-button form.
        self::assertCount(6, $crawler->filter('.dp-matrix-form'));
        self::assertCount(6, $crawler->filter('button.dp-matrix-dot'));
        self::assertCount(0, $crawler->filter('span.dp-matrix-dot'));

        $protection = $departments[1];
        $patrols = $modules[0];
        $form = $crawler->filter('.dp-matrix tbody tr')->eq(1)->filter('.dp-matrix-form')->eq(0);
        self::assertSame(
            \sprintf('/departments/%s/modules/%s/toggle', $protection->getUuidString(), $patrols->getUuidString()),
            $form->attr('action'),
        );
        self::assertSame('post', $form->attr('method'));
        self::assertNotSame('', (string) $form->filter('input[name="_token"]')->attr('value'), 'the CSRF token is stamped');
    }

    public function testTheGridDrawsARowPerDepartmentWithFilledDotsOnlyWhereAttached(): void
    {
        [$departments, $modules] = $this->fixture();
        $crawler = $this->renderAs(TeamRoleEnum::Manager, $departments, $modules);

        $rows = $crawler->filter('.dp-matrix tbody tr');
        self::assertCount(2, $rows);
        self::assertSame('Ecology', $rows->eq(0)->filter('.dp-matrix-in b')->text());
        self::assertSame('Protection Service', $rows->eq(1)->filter('.dp-matrix-in b')->text());
        self::assertSame('DP·01', $rows->eq(0)->filter('.dp-matrix-pl')->text());
        self::assertSame('DP·02', $rows->eq(1)->filter('.dp-matrix-pl')->text());

        // Ecology claims Incidents only; Protection Service claims Patrols and Incidents.
        self::assertSame(
            [false, true, false],
            $this->dotStates($rows->eq(0)),
            'Ecology: only the Incidents dot is filled',
        );
        self::assertSame(
            [true, true, false],
            $this->dotStates($rows->eq(1)),
            'Protection Service: Patrols and Incidents filled, Tourism empty',
        );
    }

    public function testTheColumnTwoDepartmentsClaimIsTintedAndCounted(): void
    {
        [$departments, $modules] = $this->fixture();
        $crawler = $this->renderAs(TeamRoleEnum::Manager, $departments, $modules);

        // Column order is the modules order: Patrols, Incidents, Tourism.
        $heads = $crawler->filter('.dp-matrix thead th.dp-matrix-rot');
        self::assertSame(['Patrols', 'Incidents', 'Tourism'], $heads->each(static fn (Crawler $th): string => $th->text()));
        self::assertSame(
            [false, true, false],
            $heads->each(static fn (Crawler $th): bool => str_contains((string) $th->attr('class'), 'dp-matrix-shared')),
            'only the shared module gets the accented header',
        );

        // The tint runs down the whole column, not just one cell.
        self::assertCount(2, $crawler->filter('.dp-matrix tbody td.dp-matrix-cell.dp-matrix-shared'));

        $foot = $crawler->filter('.dp-matrix tfoot td');
        // Tourism is claimed by nobody — the count says 0 rather than leaving the column blank.
        self::assertSame(['1', '2', '0'], $foot->each(static fn (Crawler $td): string => $td->text()));
        self::assertSame(
            [false, true, false],
            $foot->each(static fn (Crawler $td): bool => str_contains((string) $td->attr('class'), 'dp-matrix-shared')),
        );
    }

    public function testStaffSeeTheSameGridWithInertDots(): void
    {
        [$departments, $modules] = $this->fixture();
        $crawler = $this->renderAs(TeamRoleEnum::Staff, $departments, $modules);

        self::assertCount(2, $crawler->filter('.dp-matrix tbody tr'), 'the reading is unchanged');
        self::assertCount(0, $crawler->filter('form'), 'nothing to submit without the permission');
        self::assertCount(6, $crawler->filter('span.dp-matrix-dot'));
        self::assertCount(3, $crawler->filter('span.dp-matrix-dot.dp-matrix-on'));
        self::assertStringNotContainsString('click to attach', $crawler->filter('.dp-matrix-legend')->text());
    }

    public function testTheWideTableScrollsInsideTheWidget(): void
    {
        [$departments, $modules] = $this->fixture();
        $crawler = $this->renderAs(TeamRoleEnum::Manager, $departments, $modules);

        // The scroll container sits between the card and the table — the page never
        // scrolls sideways to reveal a column.
        self::assertCount(1, $crawler->filter('.c[data-w="matrix"] > .dp-matrix-wrap > table.dp-matrix'));
    }

    public function testWithoutDepartmentsTheCardSaysSoInsteadOfDrawingAnEmptyGrid(): void
    {
        $modules = [ModuleFactory::createOne(['name' => 'Patrols', 'slug' => 'patrols'])];
        $crawler = $this->renderAs(TeamRoleEnum::Manager, [], $modules);

        self::assertCount(0, $crawler->filter('.dp-matrix'));
        self::assertStringContainsString('No departments yet', $crawler->filter('.dp-matrix-hint')->text());
    }

    public function testWithoutModulesTheCardSaysSoInsteadOfDrawingAColumnlessGrid(): void
    {
        $departments = [DepartmentFactory::createOne(['name' => 'Ecology'])];
        $crawler = $this->renderAs(TeamRoleEnum::Manager, $departments, []);

        self::assertCount(0, $crawler->filter('.dp-matrix'));
        self::assertStringContainsString('No modules in the catalogue yet', $crawler->filter('.dp-matrix-hint')->text());
    }

    /**
     * THE MODULE OPEN/CLOSED RULE, on this widget: installing a module grows the matrix and
     * NOTHING is edited to make that happen.
     *
     * The columns are the installed catalogue ({@see \Uhifadhi\Repository\ModuleRepository::catalogue()}),
     * never a list this template or its service knows by name — so a module scaffolded tomorrow
     * appears here at install with zero edits, exactly as it appears in the modules tab and the
     * permission catalogue.
     *
     * Deliberately driven through the REAL PAGE rather than the render harness the other tests
     * use: the harness is handed a `modules` list, so it could only ever prove the template
     * loops over what it is given. The claim worth pinning is that the PAGE asks the catalogue,
     * and the only way to prove that is to add a row and ask for the page.
     */
    public function testAModuleAddedToTheCatalogueBecomesAColumnWithNoTemplateChange(): void
    {
        ModuleFactory::createOne(['name' => 'Patrols', 'slug' => 'patrols', 'position' => 1]);
        DepartmentFactory::createOne(['name' => 'Ecology']);
        $this->loginAs($this->client, TeamRoleEnum::Manager);

        $before = $this->matrixColumns();
        self::assertSame(['Patrols'], $before);

        // The install of a module the host was never edited for. Nothing else changes.
        ModuleFactory::createOne(['name' => 'Incidents', 'slug' => 'incidents', 'position' => 2]);

        self::assertSame(
            ['Patrols', 'Incidents'],
            $this->matrixColumns(),
            'A module in the catalogue must become a matrix column without anyone editing the widget.',
        );
    }

    /**
     * The matrix's column headings, as the widget library renders them — the library is used
     * because the matrix is off in the board's default preset, and the library renders every
     * widget the surface ships whatever the active layout holds.
     *
     * @return list<string>
     */
    private function matrixColumns(): array
    {
        $crawler = $this->client->request('GET', '/departments/widgets');
        self::assertResponseIsSuccessful();

        return $crawler
            ->filter('['.WidgetDom::TEMPLATE.'="matrix"] .dp-matrix-rot span')
            ->each(static fn (Crawler $node): string => trim($node->text()));
    }

    /**
     * Two departments, three modules, one module claimed by both — the smallest fixture that
     * still has a shared column.
     *
     * @return array{0: list<Department>, 1: list<Module>}
     */
    private function fixture(): array
    {
        $patrols = ModuleFactory::createOne(['name' => 'Patrols', 'slug' => 'patrols']);
        $incidents = ModuleFactory::createOne(['name' => 'Incidents', 'slug' => 'incidents']);
        $tourism = ModuleFactory::createOne(['name' => 'Tourism', 'slug' => 'tourism']);

        $ecology = DepartmentFactory::createOne(['name' => 'Ecology', 'modules' => [$incidents]]);
        $protection = DepartmentFactory::createOne(['name' => 'Protection Service', 'modules' => [$patrols, $incidents]]);

        return [[$ecology, $protection], [$patrols, $incidents, $tourism]];
    }

    /**
     * Renders the widget as a user of the given tier would meet it. The page will hand the
     * widget `canManage`; department administration sits with the same tiers that administer
     * the team (Manager and up), so that is what the harness asks.
     *
     * @param list<Department> $departments
     * @param list<Module>     $modules
     */
    private function renderAs(TeamRoleEnum $tier, array $departments, array $modules): Crawler
    {
        $user = $this->loginAs($this->client, $tier);
        $container = static::getContainer();

        // csrf_token() reads a session-backed store and no controller has run here, so the
        // harness supplies the request that store needs.
        $requests = $container->get(RequestStack::class);
        \assert($requests instanceof RequestStack);
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requests->push($request);

        $this->pinTheToggleRoute($container->get(RouterInterface::class));

        $twig = $container->get(Environment::class);
        \assert($twig instanceof Environment);

        $repository = $container->get(DepartmentRepository::class);
        \assert($repository instanceof DepartmentRepository);

        return new Crawler($twig->render(self::TEMPLATE, [
            'departments' => $departments,
            'modules' => $modules,
            'departmentsByModule' => $repository->departmentsByModule($modules),
            'positionsByDepartment' => [],
            'usersByDepartment' => [],
            'canManage' => $user->getTeamRole()->canManageTeam(),
        ]));
    }

    /**
     * The toggle endpoint is the core agent's; the widget is built against its pinned contract
     * (name `app_department_module_toggle`, path /departments/{uuid}/modules/{moduleUuid}/toggle).
     * Until it is registered the harness generates that exact path itself, so this test proves
     * the contract either way and starts passing through the real router the moment it lands.
     */
    private function pinTheToggleRoute(mixed $router): void
    {
        if ($router instanceof RouterInterface && null !== $router->getRouteCollection()->get('app_department_module_toggle')) {
            return;
        }

        static::getContainer()->set('router', new class implements UrlGeneratorInterface {
            private RequestContext $context;

            public function __construct()
            {
                $this->context = new RequestContext();
            }

            /**
             * @param array<string, mixed> $parameters
             */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                if ('app_department_module_toggle' !== $name) {
                    throw new \RuntimeException(\sprintf('The matrix widget generated an unexpected route "%s".', $name));
                }

                $uuid = $parameters['uuid'] ?? null;
                $moduleUuid = $parameters['moduleUuid'] ?? null;
                if (!\is_string($uuid) || !\is_string($moduleUuid)) {
                    throw new \RuntimeException('The toggle needs both a department and a module UUID.');
                }

                return \sprintf('/departments/%s/modules/%s/toggle', $uuid, $moduleUuid);
            }

            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->context;
            }
        });
    }

    /**
     * Whether each dot in a row is filled, left to right.
     *
     * @return list<bool>
     */
    private function dotStates(Crawler $row): array
    {
        return $row->filter('.dp-matrix-dot')->each(
            static fn (Crawler $dot): bool => str_contains((string) $dot->attr('class'), 'dp-matrix-on'),
        );
    }
}
