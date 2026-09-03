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
use Twig\Environment;
use Uhifadhi\Entity\Department;
use Uhifadhi\Entity\Position;
use Uhifadhi\Entity\User;
use Uhifadhi\Enum\PermissionEnum;
use Uhifadhi\Factory\DepartmentFactory;
use Uhifadhi\Factory\ModuleFactory;
use Uhifadhi\Factory\PositionFactory;
use Uhifadhi\Factory\UserFactory;
use UhifadhiLabs\Trunk\Entity\Module;
use Zenstruck\Foundry\Test\Factories;

/**
 * Design-group A's three departments widgets — the KPI strip, the department cards and the
 * shared-modules table (designs/ngoro-departments-a.html) — rendered against real entities.
 *
 * The /departments page is another agent's, and its route does not exist yet, so these render
 * the partials straight through Twig with exactly the variables the page and the widget library
 * pass them. When the route lands this stays honest: it tests the widgets, not the page.
 */
final class DepartmentWidgetGroupATest extends KernelTestCase
{
    use Factories;

    public function testTheKpiStripCountsDepartmentsPeoplePositionsAndSharedModules(): void
    {
        $crawler = new Crawler($this->render('kpis', $this->twoDepartmentsSharingAModule()));

        $plates = $crawler->filter('.dp-kstrip .c.kpi');
        self::assertCount(4, $plates);
        // 2 departments · 3 people placed · 2 positions · 2 modules attached (1 of them shared).
        self::assertSame('2', $plates->eq(0)->filter('b')->text());
        self::assertSame('3', $plates->eq(1)->filter('b')->text());
        self::assertSame('2', $plates->eq(2)->filter('b')->text());
        self::assertSame('2', $plates->eq(3)->filter('b')->text());
        self::assertStringContainsString('1 shared across departments', $plates->eq(3)->filter('.sub')->text());
    }

    public function testTheCardsNameEveryDepartmentAndMarkTheSharedModule(): void
    {
        $crawler = new Crawler($this->render('cards', $this->twoDepartmentsSharingAModule()));

        $cards = $crawler->filter('.dp-cardgrid .dp-card');
        self::assertCount(2, $cards);
        self::assertStringContainsString('Protection Service', $cards->eq(0)->filter('.tab')->text());
        self::assertStringContainsString('2 members', $cards->eq(0)->filter('.tab')->text());
        self::assertStringContainsString('Ecology', $cards->eq(1)->filter('.tab')->text());

        // Incidents sits in both departments, so both cards light it and say how many claim it.
        foreach ([0, 1] as $i) {
            $shared = $cards->eq($i)->filter('.dp-chip.shared');
            self::assertCount(1, $shared);
            self::assertStringContainsString('Incidents', $shared->text());
            self::assertStringContainsString('·2', $shared->text());
        }
        // Wildlife belongs to Ecology alone — a plain chip, never the shared treatment.
        $plain = $cards->eq(1)->filter('.dp-chip:not(.shared)');
        self::assertStringContainsString('Wildlife', $plain->text());

        // Positions carry their permissions and how many people hold them.
        $row = $cards->eq(0)->filter('.dp-posrow')->eq(0);
        self::assertStringContainsString('Ranger', $row->filter('.pn')->text());
        self::assertStringContainsString('module.view', $row->filter('.perm')->text());
        self::assertSame('2 held', $row->filter('.hb')->text());

        // The member stack shows the people, not a placeholder.
        self::assertCount(2, $cards->eq(0)->filter('.dp-astack .avatar'));
        // The principle the design puts above the cards.
        self::assertStringContainsString('A lens, not a fence.', $crawler->filter('.dp-lensnote')->text());
    }

    public function testTheSharedTableListsOnlyModulesTwoDepartmentsClaim(): void
    {
        $crawler = new Crawler($this->render('shared', $this->twoDepartmentsSharingAModule()));

        $rows = $crawler->filter('table.tbl tbody tr');
        self::assertCount(1, $rows);
        self::assertSame('Incidents', $rows->eq(0)->filter('td b')->text());
        self::assertStringContainsString('Protection Service', $rows->eq(0)->filter('.dp-claim')->text());
        self::assertStringContainsString('Ecology', $rows->eq(0)->filter('.dp-claim')->text());
        self::assertSame('2', $rows->eq(0)->filter('td.num')->text());
        // Wildlife has a single department and never reaches the table.
        self::assertStringNotContainsString('Wildlife', $crawler->filter('table.tbl')->text());
    }

    public function testTheSharedTableSaysSoWhenNothingIsSharedYet(): void
    {
        $department = DepartmentFactory::createOne(['name' => 'Ecology']);
        $module = ModuleFactory::createOne(['name' => 'Wildlife', 'slug' => 'wildlife']);
        $department->addModule($module);

        $crawler = new Crawler($this->render('shared', [
            'departments' => [$department],
            'modules' => [$module],
            'departmentsByModule' => [$module->getId() => [$department]],
            'positionsByDepartment' => [],
            'usersByDepartment' => [],
            'canManage' => true,
        ]));

        self::assertCount(0, $crawler->filter('table.tbl'));
        self::assertStringContainsString('No module is claimed by two departments yet', $crawler->text());
    }

    public function testNoDepartmentsYieldsAnInvitationRatherThanAnEmptyGrid(): void
    {
        $crawler = new Crawler($this->render('cards', [
            'departments' => [],
            'modules' => [],
            'departmentsByModule' => [],
            'positionsByDepartment' => [],
            'usersByDepartment' => [],
            'canManage' => true,
        ]));

        self::assertCount(0, $crawler->filter('.dp-cardgrid'));
        self::assertStringContainsString('No departments yet', $crawler->filter('.c')->text());
        self::assertStringContainsString('A lens, not a fence.', $crawler->filter('.c')->text());
    }

    /**
     * Protection Service (Incidents) and Ecology (Incidents + Wildlife): two departments,
     * one module between them, two positions, three people.
     *
     * @return array{departments: list<Department>, modules: list<Module>,
     *     departmentsByModule: array<int, list<Department>>,
     *     positionsByDepartment: array<int, list<Position>>,
     *     usersByDepartment: array<int, list<User>>, canManage: bool}
     */
    private function twoDepartmentsSharingAModule(): array
    {
        $protection = DepartmentFactory::createOne(['name' => 'Protection Service']);
        $ecology = DepartmentFactory::createOne(['name' => 'Ecology']);

        $incidents = ModuleFactory::createOne(['name' => 'Incidents', 'slug' => 'incidents']);
        $wildlife = ModuleFactory::createOne(['name' => 'Wildlife', 'slug' => 'wildlife']);

        $protection->addModule($incidents);
        $ecology->addModule($incidents);
        $ecology->addModule($wildlife);

        $ranger = PositionFactory::new(['name' => 'Ranger', 'department' => $protection])
            ->withPermissions([PermissionEnum::ModuleView, PermissionEnum::IngestionRun])
            ->create();
        $ecologist = PositionFactory::new(['name' => 'Ecologist', 'department' => $ecology])
            ->withPermissions([PermissionEnum::ModuleView])
            ->create();

        $rangers = [
            UserFactory::createOne(['firstName' => 'Asha', 'lastName' => 'Mollel', 'position' => $ranger]),
            UserFactory::createOne(['firstName' => 'Juma', 'lastName' => 'Saidi', 'position' => $ranger]),
        ];
        $ecologists = [
            UserFactory::createOne(['firstName' => 'Grace', 'lastName' => 'Shayo', 'position' => $ecologist]),
        ];

        return [
            'departments' => [$protection, $ecology],
            'modules' => [$incidents, $wildlife],
            'departmentsByModule' => [
                (int) $incidents->getId() => [$protection, $ecology],
                (int) $wildlife->getId() => [$ecology],
            ],
            'positionsByDepartment' => [
                (int) $protection->getId() => [$ranger],
                (int) $ecology->getId() => [$ecologist],
            ],
            'usersByDepartment' => [
                (int) $protection->getId() => $rangers,
                (int) $ecology->getId() => $ecologists,
            ],
            'canManage' => true,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $widget, array $context): string
    {
        self::bootKernel();

        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->render(\sprintf('departments/_w_%s.html.twig', $widget), $context);
    }
}
