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

namespace Uhifadhi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Entity\Department;
use Uhifadhi\Entity\Position;
use Uhifadhi\Entity\User;
use Uhifadhi\Service\DepartmentLens;
use Uhifadhi\Trunk\Entity\Module;

/**
 * The department lens: a re-ordering, never a filter. Every module handed in comes back out —
 * the ones attached to the viewer's department simply lead. A viewer without a department (no
 * user, no position, or a position outside every department) sees the input untouched.
 */
final class DepartmentLensTest extends TestCase
{
    private function lens(): DepartmentLens
    {
        return new DepartmentLens();
    }

    /**
     * @param list<Module> $modules
     *
     * @return list<string|null>
     */
    private function slugs(array $modules): array
    {
        return array_map(static fn (Module $module): ?string => $module->getSlug(), $modules);
    }

    public function testDepartmentModulesLeadAndEveryoneKeepsTheirOriginalRelativeOrder(): void
    {
        $overview = new Module()->setSlug('overview')->setName('Overview');
        $patrols = new Module()->setSlug('patrols')->setName('Patrols');
        $forest = new Module()->setSlug('forest')->setName('Forest loss');
        $incidents = new Module()->setSlug('incidents')->setName('Incidents');

        $department = new Department()->setName('Protection')
            ->addModule($patrols)
            ->addModule($incidents);
        $user = new User()->setPosition(new Position()->setName('Ranger')->setDepartment($department));

        self::assertSame(
            ['patrols', 'incidents', 'overview', 'forest'],
            $this->slugs($this->lens()->moduleOrderFor($user, [$overview, $patrols, $forest, $incidents])),
            "the department's modules lead, both groups keeping their input order",
        );
    }

    public function testAUserWithoutADepartmentGetsTheInputUnchanged(): void
    {
        $overview = new Module()->setSlug('overview');
        $forest = new Module()->setSlug('forest');
        $input = [$overview, $forest];
        $lens = $this->lens();

        self::assertSame($input, $lens->moduleOrderFor(null, $input), 'no user at all');
        self::assertSame($input, $lens->moduleOrderFor(new User(), $input), 'a user with no position');
        self::assertSame(
            $input,
            $lens->moduleOrderFor(new User()->setPosition(new Position()->setName('Ranger')), $input),
            'a position that belongs to no department',
        );
    }

    public function testOrderIsStableWhenTheDepartmentHoldsAllOrNoneOfTheModules(): void
    {
        $a = new Module()->setSlug('a');
        $b = new Module()->setSlug('b');
        $lens = $this->lens();

        $empty = new Department()->setName('Finance');
        $user = new User()->setPosition(new Position()->setName('Clerk')->setDepartment($empty));
        self::assertSame(['a', 'b'], $this->slugs($lens->moduleOrderFor($user, [$a, $b])));

        $empty->addModule($a)->addModule($b);
        self::assertSame(['a', 'b'], $this->slugs($lens->moduleOrderFor($user, [$a, $b])));
    }

    public function testNothingIsEverDroppedOrAdded(): void
    {
        $a = new Module()->setSlug('a');
        $b = new Module()->setSlug('b');
        $c = new Module()->setSlug('c');

        $department = new Department()->setName('Ecology')->addModule($c);
        $user = new User()->setPosition(new Position()->setName('Ecologist')->setDepartment($department));

        $ordered = $this->lens()->moduleOrderFor($user, [$a, $b, $c]);

        self::assertCount(3, $ordered, 'a lens never gates data');
        self::assertSame(['c', 'a', 'b'], $this->slugs($ordered));
        self::assertSame([], array_udiff([$a, $b, $c], $ordered, static fn (Module $x, Module $y): int => spl_object_id($x) <=> spl_object_id($y)));
    }
}
