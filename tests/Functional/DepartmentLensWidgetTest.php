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
use Twig\TwigFunction;
use Uhifadhi\Entity\User;
use Uhifadhi\Factory\DepartmentFactory;
use Uhifadhi\Factory\ModuleFactory;
use Uhifadhi\Factory\PositionFactory;
use Uhifadhi\Factory\UserFactory;
use Uhifadhi\Service\DepartmentLens;
use Uhifadhi\Trunk\Entity\Module;
use Zenstruck\Foundry\Test\Factories;

/**
 * The Departments `lens` widget: a PREVIEW of the seam — the same area module list as two
 * different departments meet it. It is built from real departments, real modules and real
 * positions, and its ordering is the ordering {@see DepartmentLens} performs on the real
 * Modules tab, so the preview cannot promise something the app does not do. Pure render:
 * no forms, no links, no controls.
 *
 * Rendered through a Twig harness rather than a page: the /departments screen is being built
 * alongside this partial (see {@see DepartmentRegistryWidgetTest} for the same approach).
 */
final class DepartmentLensWidgetTest extends KernelTestCase
{
    use Factories;

    private const TEMPLATE = 'departments/_w_lens.html.twig';

    /** Every route this widget is allowed to reach — see the note on the harness below. */
    private const array ROUTES = ['app_department_show'];

    public function testItPreviewsTheFirstTwoDepartmentsThatHaveModules(): void
    {
        self::bootKernel();
        [$context, $modules, $rangers] = $this->fixture();

        $crawler = $this->render($context);

        $panels = $crawler->filter('.lensc');
        self::assertCount(2, $panels, 'two side-by-side signed-in states — the departments with modules');
        self::assertStringContainsString('Protection Service', $panels->eq(0)->text());
        self::assertStringContainsString('Ecology', $panels->eq(1)->text());
        self::assertStringNotContainsString(
            'Human Resource',
            $crawler->text(),
            'a department with no modules produces no lens to preview',
        );

        // The persona is a real member on a real position of that department.
        self::assertStringContainsString('Ranger', $panels->eq(0)->filter('.persona')->text());
        self::assertStringContainsString('Research Analyst', $panels->eq(1)->filter('.persona')->text());

        // THE PIN: the chip order the preview shows is the order the real lens produces.
        $lens = new DepartmentLens();
        foreach ([0 => $rangers['protection'], 1 => $rangers['ecology']] as $index => $viewer) {
            self::assertSame(
                array_map(static fn (Module $m): ?string => $m->getName(), $lens->moduleOrderFor($viewer, $modules)),
                $panels->eq($index)->filter('.snav .dchip')->each(static fn (Crawler $c): string => trim($c->text())),
                'the preview must order by the same lens the Modules tab uses',
            );
        }

        // The leading group is the department's own modules; everything else stays one click away.
        self::assertSame(
            ['Patrols', 'Incidents'],
            $panels->eq(0)->filter('.mmg .mmc .nm')->each(
                static fn (Crawler $c): string => trim(str_replace('shared', '', $c->text())),
            ),
        );
        self::assertSame(
            ['Wildlife'],
            $panels->eq(0)->filter('.chiprow .dchip.ghost')->each(static fn (Crawler $c): string => trim($c->text())),
        );
        self::assertSame(
            ['Patrols'],
            $panels->eq(1)->filter('.chiprow .dchip.ghost')->each(static fn (Crawler $c): string => trim($c->text())),
        );
    }

    public function testAModuleTwoDepartmentsClaimIsMarkedSharedInBothLenses(): void
    {
        self::bootKernel();
        [$context] = $this->fixture();

        $crawler = $this->render($context);

        foreach ([0, 1] as $index) {
            $shared = $crawler->filter('.lensc')->eq($index)->filter('.mmc .bo');
            self::assertCount(1, $shared, 'only the module both departments claim carries the marker');
            self::assertSame('shared', trim($shared->text()));
        }
        self::assertStringContainsString(
            'A lens, not a fence',
            $crawler->text(),
            'the principle the preview exists to make visible',
        );
    }

    public function testItIsAPureRenderWithNoControls(): void
    {
        self::bootKernel();
        [$context] = $this->fixture();

        $crawler = $this->render($context);

        self::assertCount(0, $crawler->filter('form'));
        self::assertCount(0, $crawler->filter('button'));
        self::assertCount(0, $crawler->filter('input'));

        // NAVIGATION IS NOT A CONTROL. The one link a persona card carries is the department it
        // names, in the caption ABOVE the mock — every widget that names a department is a way
        // into that department's record. Inside .frame nothing is a link: that is the preview,
        // and a link in there would navigate out of the very screen being previewed.
        self::assertSame(
            array_fill(0, $crawler->filter('.lensc')->count(), 'dp-deptlink'),
            $crawler->filter('a')->each(static fn (Crawler $node): string => (string) $node->attr('class')),
        );
        self::assertCount(0, $crawler->filter('.frame a'));
    }

    public function testWithoutTwoDepartmentsCarryingModulesItSaysSoHonestly(): void
    {
        self::bootKernel();
        $module = ModuleFactory::createOne(['name' => 'Patrols']);
        $protection = DepartmentFactory::createOne(['name' => 'Protection Service', 'modules' => [$module]]);
        $humanResource = DepartmentFactory::createOne(['name' => 'Human Resource', 'modules' => []]);

        $crawler = $this->render([
            'departments' => [$protection, $humanResource],
            'modules' => [$module],
            'departmentsByModule' => [(int) $module->getId() => [$protection]],
            'positionsByDepartment' => [],
            'usersByDepartment' => [],
            'canManage' => true,
        ]);

        self::assertCount(0, $crawler->filter('.lensc'), 'no invented personas');
        $empty = $crawler->filter('.lens-empty');
        self::assertCount(1, $empty);
        self::assertStringContainsString('modules', $empty->text());
        self::assertStringContainsString('positions', $empty->text());
    }

    /**
     * Two departments with modules (one shared between them), one without — each with a real
     * position and a real member, so the personas are never invented.
     *
     * @return array{array<string, mixed>, list<Module>, array<string, User>}
     */
    private function fixture(): array
    {
        $patrols = ModuleFactory::createOne(['name' => 'Patrols']);
        $incidents = ModuleFactory::createOne(['name' => 'Incidents']);
        $wildlife = ModuleFactory::createOne(['name' => 'Wildlife']);

        $protection = DepartmentFactory::createOne(['name' => 'Protection Service', 'modules' => [$patrols, $incidents]]);
        $ecology = DepartmentFactory::createOne(['name' => 'Ecology', 'modules' => [$wildlife, $incidents]]);
        $humanResource = DepartmentFactory::createOne(['name' => 'Human Resource', 'modules' => []]);

        $ranger = PositionFactory::createOne(['name' => 'Ranger', 'department' => $protection]);
        $analyst = PositionFactory::createOne(['name' => 'Research Analyst', 'department' => $ecology]);
        $mollel = UserFactory::createOne(['firstName' => 'Anna', 'lastName' => 'Mollel', 'position' => $ranger]);
        $shirima = UserFactory::createOne(['firstName' => 'Grace', 'lastName' => 'Shirima', 'position' => $analyst]);

        $modules = [$patrols, $incidents, $wildlife];

        return [
            [
                'departments' => [$protection, $ecology, $humanResource],
                'modules' => $modules,
                'departmentsByModule' => [
                    (int) $patrols->getId() => [$protection],
                    (int) $incidents->getId() => [$ecology, $protection],
                    (int) $wildlife->getId() => [$ecology],
                ],
                'positionsByDepartment' => [
                    (int) $protection->getId() => [$ranger],
                    (int) $ecology->getId() => [$analyst],
                    (int) $humanResource->getId() => [],
                ],
                'usersByDepartment' => [
                    (int) $protection->getId() => [$mollel],
                    (int) $ecology->getId() => [$shirima],
                    (int) $humanResource->getId() => [],
                ],
                'canManage' => false,
            ],
            $modules,
            ['protection' => $mollel, 'ecology' => $shirima],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(array $context): Crawler
    {
        $twig = self::getContainer()->get('twig');
        \assert($twig instanceof Environment);

        $harness = new Environment($twig->getLoader(), ['strict_variables' => true]);
        // THE ALLOW-LIST. The preview links to exactly one place — the department its persona
        // card names — and nowhere else: the mock frame below it is a picture of somebody else's
        // screen, and a link in there would navigate out of the very thing being previewed. Any
        // other route reached from this widget fails here rather than in a browser.
        $harness->addFunction(new TwigFunction(
            'path',
            /** @param array<string, mixed> $parameters */
            static fn (string $name, array $parameters = []): string => \in_array($name, self::ROUTES, true)
                ? '/departments/'.(\is_string($parameters['uuid'] ?? null) ? $parameters['uuid'] : '')
                : self::fail(\sprintf(
                    'The lens widget may only link to %s, got a path to "%s".',
                    implode(', ', self::ROUTES),
                    $name,
                )),
        ));
        $harness->addFunction(new TwigFunction(
            'ux_icon',
            static fn (string $name): string => '<svg data-icon="'.$name.'"></svg>',
            ['is_safe' => ['html']],
        ));

        return new Crawler($harness->render(self::TEMPLATE, $context));
    }
}
