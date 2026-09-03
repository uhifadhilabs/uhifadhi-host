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

namespace Uhifadhi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Compiler\RegisterAutoconfigureAttributesPass;
use Symfony\Component\DependencyInjection\Compiler\ResolveInstanceofConditionalsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Uhifadhi\Entity\Department;
use Uhifadhi\Module\DepartmentKpi;
use Uhifadhi\Module\DepartmentKpiProviderInterface;
use Uhifadhi\Seam\Entity\Module;
use Uhifadhi\Service\DepartmentKpiService;

/**
 * The WIRING of the department-KPI seam, which no unit test can prove: that a class implementing
 * {@see DepartmentKpiProviderInterface} really is collected by the container.
 *
 * This matters more than it looks. The tag is applied by an `#[AutoconfigureTag]` on the interface
 * rather than by `Kernel::registerForAutoconfiguration()` — chosen so the seam is self-contained
 * and a module bundle applies the same tag explicitly, exactly as it already does for
 * `uhifadhi.module`. If that attribute were dropped the app would still boot, every page would
 * still render, and every figure would silently vanish: the honest-empty rendering would hide the
 * bug perfectly. Hence a test that asserts the tag itself.
 *
 * A REAL ContainerBuilder rather than the app's, so the test registers its stub provider without
 * adding a service to the shipped container or to shared test config. The `autoconfigure(true)`
 * below is the one line config/services.yaml applies to everything in src/.
 */
final class DepartmentKpiTagTest extends TestCase
{
    public function testAHostProviderCarryingTheAttributeIsTaggedByAutoconfiguration(): void
    {
        $container = new ContainerBuilder();
        // setAutoconfigured(true) is the one line config/services.yaml applies to everything in src/.
        $container->register('stub', StubDepartmentKpiProvider::class)->setAutoconfigured(true);

        new RegisterAutoconfigureAttributesPass()->process($container);
        new ResolveInstanceofConditionalsPass()->process($container);

        self::assertTrue(
            $container->getDefinition('stub')->hasTag(DepartmentKpiProviderInterface::TAG),
            'A department KPI provider was not tagged — every figure on every performance surface would silently vanish.',
        );
    }

    public function testTheAttributeMustSitOnTheProviderAndNotOnTheInterface(): void
    {
        // The trap this pins: RegisterAutoconfigureAttributesPass reads attributes off the
        // DEFINITION'S OWN CLASS, and PHP does not inherit attributes from an implemented
        // interface. An #[AutoconfigureTag] written on DepartmentKpiProviderInterface would be
        // silently dead — the app would boot, the pages would render, and every figure would
        // quietly vanish. So the interface must NOT carry one, and the docblock must keep saying
        // where it belongs instead.
        self::assertSame(
            [],
            new \ReflectionClass(DepartmentKpiProviderInterface::class)->getAttributes(),
            'The tag attribute on this interface is never read; put it on each provider class instead.',
        );

        $container = new ContainerBuilder();
        $container->register('untagged', UntaggedDepartmentKpiProvider::class)->setAutoconfigured(true);
        new RegisterAutoconfigureAttributesPass()->process($container);
        new ResolveInstanceofConditionalsPass()->process($container);

        self::assertFalse($container->getDefinition('untagged')->hasTag(DepartmentKpiProviderInterface::TAG));
    }

    public function testTheTagIsTheOneTheServiceCollects(): void
    {
        // The service and the interface must name the SAME string, and neither may be retyped:
        // a typo here is a container that collects nothing and complains about nothing.
        self::assertSame('uhifadhi.department_kpi', DepartmentKpiProviderInterface::TAG);

        $iterator = new \ReflectionMethod(DepartmentKpiService::class, '__construct')
            ->getParameters()[0]
            ->getAttributes(\Symfony\Component\DependencyInjection\Attribute\AutowireIterator::class);

        self::assertCount(1, $iterator, 'DepartmentKpiService does not collect a tagged iterator.');
        self::assertSame(DepartmentKpiProviderInterface::TAG, $iterator[0]->getArguments()[0]);
    }

    public function testACollectedProviderIsAskedOnlyForAnAttachedModule(): void
    {
        $service = new DepartmentKpiService([new StubDepartmentKpiProvider()]);
        $now = new \DateTimeImmutable();

        $ecology = new Department()->setName('Ecology');
        $ecology->addModule(new Module()->setSlug(StubDepartmentKpiProvider::SLUG)->setName('Stub'));

        self::assertCount(1, $service->forDepartment($ecology, $now));
        // Nothing attached: an empty list, and therefore a dashed slot — never a zero.
        self::assertSame([], $service->forDepartment(new Department()->setName('Tourism'), $now));
    }
}

/**
 * A module provider with no module — the smallest thing that can prove the tag works. It lives in
 * the test namespace and is registered only in the throwaway container above, so the app ships
 * nothing extra.
 */
#[AutoconfigureTag(DepartmentKpiProviderInterface::TAG)]
final class StubDepartmentKpiProvider implements DepartmentKpiProviderInterface
{
    public const string SLUG = 'stub-module';

    public function moduleSlug(): string
    {
        return self::SLUG;
    }

    public function kpisFor(Department $department, \DateTimeImmutable $now): array
    {
        return [new DepartmentKpi('stubbed', 'Stubbed', self::SLUG, 'Stub', 1.0)];
    }
}

/** The same provider WITHOUT the attribute — implementing the interface alone tags nothing. */
final class UntaggedDepartmentKpiProvider implements DepartmentKpiProviderInterface
{
    public function moduleSlug(): string
    {
        return 'untagged-module';
    }

    public function kpisFor(Department $department, \DateTimeImmutable $now): array
    {
        return [];
    }
}
