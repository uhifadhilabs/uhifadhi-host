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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;
use Uhifadhi\Entity\Department;
use Uhifadhi\Entity\Position;
use Uhifadhi\Factory\DepartmentFactory;
use Uhifadhi\Factory\ModuleFactory;
use Uhifadhi\Factory\PositionFactory;
use Uhifadhi\Factory\UserFactory;
use Uhifadhi\Repository\DepartmentRepository;
use Uhifadhi\Seam\Entity\Module;
use Zenstruck\Foundry\Test\Factories;

/**
 * The departments 'lanes' widget — the org chart. One lane per department hanging off a rail
 * under the organization node, module chips at the top of each lane, one group per position
 * with the faces of the people who hold it.
 *
 * Rendered through Twig directly rather than through a route: the org-wide /departments page
 * (and the widget catalogue that places this widget at span 12) is a sibling agent's work and
 * is not live yet. When it lands, this test keeps its value — it pins the widget's contract
 * (the six variables it is handed) independently of whichever page composes it.
 */
final class DepartmentLanesWidgetTest extends KernelTestCase
{
    use Factories;

    private const string TEMPLATE = 'departments/_w_lanes.html.twig';

    public function testTheChartDrawsALanePerDepartmentWithItsChipsPositionsAndFaces(): void
    {
        $crawler = new Crawler($this->render($this->fixture()));

        // The root node + the connectors that make it a chart rather than a list.
        self::assertCount(1, $crawler->filter('.dp-lanes-root .dp-lanes-rootcard'));
        self::assertCount(1, $crawler->filter('.dp-lanes-root .dp-lanes-stem'), 'a stem must drop from the org node');
        self::assertCount(1, $crawler->filter('.dp-lanes-rail'), 'the lanes hang off one rail');
        self::assertCount(2, $crawler->filter('.dp-lanes-grid > .dp-lanes-lane'));

        // The organization: nothing in the app names one, so the node is labelled generically
        // and its counts are the sums of the lanes drawn below it (7 people, 3 positions).
        self::assertSame('Organization', trim($crawler->filter('.dp-lanes-rootcard b')->text()));
        $rootMeta = $this->squash($crawler->filter('.dp-lanes-rootcard span')->text());
        self::assertSame('7 people · 2 departments · 3 positions', $rootMeta);

        $protection = $crawler->filter('.dp-lanes-lane')->eq(0);
        self::assertSame('DP·01', $protection->filter('.dp-lanes-pl')->text());
        self::assertSame('Protection Service', $protection->filter('.dp-lanes-lh b')->text());
        self::assertSame('6 people · 2 positions', $this->squash($protection->filter('.dp-lanes-lm')->text()));

        // Module chips, in catalogue order, at the top of the lane.
        self::assertSame(
            ['Patrols', 'Incidents'],
            $protection->filter('.dp-lanes-chip')->each(static fn (Crawler $c): string => preg_replace('/·\d+$/', '', trim($c->text())) ?? ''),
        );

        // Position groups: label, holder count, and the faces themselves.
        self::assertSame(
            ['Ranger', 'Patrol Sergeant'],
            $protection->filter('.dp-lanes-ph b')->each(static fn (Crawler $c): string => $c->text()),
        );
        self::assertSame(['5', '1'], $protection->filter('.dp-lanes-ph em')->each(static fn (Crawler $c): string => $c->text()));

        $rangers = $protection->filter('.dp-lanes-pgroup')->eq(0);
        // Initials exactly as the Team roster builds them, capped at four with an honest remainder.
        self::assertSame(
            ['AM', 'PL', 'MC', 'RN'],
            $rangers->filter('.avatar.dp-lanes-face')->each(static fn (Crawler $c): string => $c->text()),
        );
        self::assertSame('Asha Mollel', $rangers->filter('.avatar.dp-lanes-face')->eq(0)->attr('title'));
        self::assertSame('+1 more', $rangers->filter('.dp-lanes-more')->text());

        $ecology = $crawler->filter('.dp-lanes-lane')->eq(1);
        self::assertSame('DP·02', $ecology->filter('.dp-lanes-pl')->text());
        self::assertSame('Ecology', $ecology->filter('.dp-lanes-lh b')->text());
        self::assertSame('EM', $ecology->filter('.avatar.dp-lanes-face')->text());
    }

    public function testAModuleInTwoLanesIsChippedAsSharedInBoth(): void
    {
        $crawler = new Crawler($this->render($this->fixture()));

        $shared = $crawler->filter('.dp-lanes-chip--shared');
        self::assertCount(2, $shared, 'Incidents sits in both lanes — a shared chip in each');
        foreach ($shared as $node) {
            $chip = new Crawler($node);
            self::assertStringStartsWith('Incidents', $chip->text());
            self::assertSame('·2', $chip->filter('i')->text(), 'the chip says how many lanes read the same rows');
        }
        // The department-only chip carries no count and no shared styling.
        self::assertSame('Patrols', $crawler->filter('.dp-lanes-chip:not(.dp-lanes-chip--shared)')->text());
    }

    public function testAnEmptyDepartmentGetsALaneWithAnHonestNote(): void
    {
        $vars = $this->fixture();
        $empty = DepartmentFactory::createOne(['name' => 'Human Resource']);
        $vars['departments'][] = $empty;
        $vars['positionsByDepartment'][(int) $empty->getId()] = [];
        $vars['usersByDepartment'][(int) $empty->getId()] = [];

        $crawler = new Crawler($this->render($vars));

        $lane = $crawler->filter('.dp-lanes-lane')->eq(2);
        self::assertStringContainsString('dp-lanes-lane--empty', (string) $lane->attr('class'));
        self::assertSame('Human Resource', $lane->filter('.dp-lanes-lh b')->text());
        self::assertSame('0 people · 0 positions', $this->squash($lane->filter('.dp-lanes-lm')->text()));
        self::assertSame('no modules', $lane->filter('.dp-lanes-chip--ghost')->text());
        self::assertStringContainsString('No positions filed here yet', $lane->filter('.dp-lanes-note')->text());
    }

    public function testTheChartIsPureRenderEvenForAManagerAndScrollsInsideItsOwnBox(): void
    {
        $vars = $this->fixture();
        $vars['canManage'] = true;

        $crawler = new Crawler($this->render($vars));

        // The weakest editing surface by design: it is read, never edited. No add-module chip,
        // no new-position control, not even an "open" link — those live in the other widgets.
        // NAVIGATION IS NOT A CONTROL, and it is the one exception to this widget's rule: each
        // lane's heading names a department, and every widget that names one is a way into its
        // record. Nothing else in the chart is clickable — no add-module chip, no new-position
        // control — so the allow-list is exactly one link per lane and nothing more.
        self::assertSame(
            array_fill(0, $crawler->filter('.dp-lanes-lane')->count(), 'dp-deptlink'),
            $crawler->filter('a')->each(static fn (Crawler $node): string => (string) $node->attr('class')),
        );
        self::assertCount(0, $crawler->filter('button'), 'the chart carries no controls');
        self::assertCount(0, $crawler->filter('form'), 'the chart carries no forms');
        self::assertCount(0, $crawler->filter('input'));

        // Eight lanes at span 12 must scroll INSIDE the widget — the page never scrolls sideways.
        self::assertCount(1, $crawler->filter('.dp-lanes > .dp-lanes-scroll > .dp-lanes-chart'));
    }

    /**
     * Two departments sharing one module: Protection Service (Patrols + Incidents, a Ranger held
     * by five and a Patrol Sergeant held by one) and Ecology (Incidents, one Ecologist).
     *
     * @return array{departments: list<Department>, modules: list<Module>, departmentsByModule: array<int, list<Department>>, positionsByDepartment: array<int, list<Position>>, usersByDepartment: array<int, list<\Uhifadhi\Entity\User>>, canManage: bool}
     */
    private function fixture(): array
    {
        self::bootKernel();

        $patrols = ModuleFactory::createOne(['slug' => 'patrols', 'name' => 'Patrols', 'position' => 1]);
        $incidents = ModuleFactory::createOne(['slug' => 'incidents', 'name' => 'Incidents', 'position' => 2]);

        $protection = DepartmentFactory::createOne(['name' => 'Protection Service', 'modules' => [$patrols, $incidents]]);
        $ecology = DepartmentFactory::createOne(['name' => 'Ecology', 'modules' => [$incidents]]);

        $ranger = PositionFactory::createOne(['name' => 'Ranger', 'department' => $protection]);
        $sergeant = PositionFactory::createOne(['name' => 'Patrol Sergeant', 'department' => $protection]);
        $ecologist = PositionFactory::createOne(['name' => 'Ecologist', 'department' => $ecology]);

        $rangers = [];
        foreach ([['Asha', 'Mollel'], ['Paulo', 'Laizer'], ['Mary', 'Chuwa'], ['Rose', 'Ndosi'], ['Juma', 'Sirikwa']] as [$first, $last]) {
            $rangers[] = UserFactory::createOne(['firstName' => $first, 'lastName' => $last, 'position' => $ranger]);
        }
        $sergeants = [UserFactory::createOne(['firstName' => 'Salma', 'lastName' => 'Kimweri', 'position' => $sergeant])];
        $ecologists = [UserFactory::createOne(['firstName' => 'Erasto', 'lastName' => 'Mushi', 'position' => $ecologist])];

        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        // Via the EM (as DepartmentRepositoryTest does): no controller consumes this repository
        // yet, so the compiled container has nothing to keep its service id alive.
        $repository = $em->getRepository(Department::class);
        \assert($repository instanceof DepartmentRepository);

        return [
            'departments' => [$protection, $ecology],
            'modules' => [$patrols, $incidents],
            'departmentsByModule' => $repository->departmentsByModule([$patrols, $incidents]),
            'positionsByDepartment' => [
                (int) $protection->getId() => [$ranger, $sergeant],
                (int) $ecology->getId() => [$ecologist],
            ],
            'usersByDepartment' => [
                (int) $protection->getId() => [...$rangers, ...$sergeants],
                (int) $ecology->getId() => $ecologists,
            ],
            'canManage' => false,
        ];
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function render(array $vars): string
    {
        $twig = self::getContainer()->get(Environment::class);
        \assert($twig instanceof Environment);

        return $twig->render(self::TEMPLATE, $vars);
    }

    /** Twig wraps the long meta lines, so compare on squashed whitespace. */
    private function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
