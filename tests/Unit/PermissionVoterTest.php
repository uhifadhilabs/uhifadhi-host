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

namespace Uhifadhi\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Uhifadhi\Entity\Position;
use Uhifadhi\Entity\User;
use Uhifadhi\Enum\PermissionEnum;
use Uhifadhi\Enum\TeamRoleEnum;
use Uhifadhi\Security\PermissionVoter;
use Uhifadhi\Service\PermissionCatalogueService;
use UhifadhiLabs\ModuleContracts\ModulePermission;
use UhifadhiLabs\ModuleContracts\ModuleProviderInterface;
use UhifadhiLabs\ModuleContracts\ModuleProviderTrait;

/**
 * The voter is the single action-level gate: managing tiers (Super Admin / Admin /
 * only) hold every permission by tier; Manager and Staff hold exactly the permissions of their
 * assigned Position and nothing more. Anything that isn't a PermissionEnum value is not
 * ours to decide — the voter abstains so other voters (role checks) can run.
 */
final class PermissionVoterTest extends TestCase
{
    private PermissionVoter $voter;

    protected function setUp(): void
    {
        // The catalogue carries the enum plus one module-declared permission, so
        // the voter's behaviour is identical for both kinds.
        $tiebreak = new class implements ModuleProviderInterface {
            use ModuleProviderTrait;

            public function slug(): string
            {
                return 'verifier';
            }

            public function name(): string
            {
                return 'Verifier';
            }

            public function category(): string
            {
                return 'pressure';
            }

            public function permissions(): array
            {
                return [new ModulePermission('verification.tiebreak', 'Verification', 'Tiebreak')];
            }
        };
        $this->voter = new PermissionVoter(new PermissionCatalogueService([$tiebreak]));
    }

    public function testStaffAreGrantedOnlyTheirPositionsPermissions(): void
    {
        $position = (new Position())
            ->setName('Ranger')
            ->setPermissions([PermissionEnum::AreaView, PermissionEnum::ModuleView]);
        $staff = (new User())
            ->setTeamRole(TeamRoleEnum::Staff)
            ->setPosition($position);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($staff, PermissionEnum::AreaView->value),
        );
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($staff, PermissionEnum::ModuleView->value),
        );
    }

    public function testStaffAreDeniedAPermissionOutsideTheirPosition(): void
    {
        $position = (new Position())
            ->setName('Ranger')
            ->setPermissions([PermissionEnum::AreaView]);
        $staff = (new User())
            ->setTeamRole(TeamRoleEnum::Staff)
            ->setPosition($position);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($staff, PermissionEnum::AreaDelete->value),
        );
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($staff, PermissionEnum::IngestionRun->value),
        );
    }

    public function testStaffWithoutAPositionAreDenied(): void
    {
        $staff = new User()->setTeamRole(TeamRoleEnum::Staff);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($staff, PermissionEnum::AreaView->value),
        );
    }

    public function testAdminTiersHoldEveryPermissionEvenWithoutAPosition(): void
    {
        $tiers = [TeamRoleEnum::SuperAdmin, TeamRoleEnum::Admin];

        foreach ($tiers as $tier) {
            $user = new User()->setTeamRole($tier);

            foreach (PermissionEnum::all() as $permission) {
                self::assertSame(
                    VoterInterface::ACCESS_GRANTED,
                    $this->vote($user, $permission->value),
                    \sprintf('%s must hold %s by tier', $tier->name, $permission->value),
                );
            }
        }
    }

    public function testItAbstainsOnAnAttributeThatIsNotAPermission(): void
    {
        $user = new User()->setTeamRole(TeamRoleEnum::SuperAdmin);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->vote($user, 'ROLE_ADMIN'),
        );
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->vote($user, 'not.a.real.permission'),
        );
    }

    public function testItDeniesWhenTheTokenHolderIsNotAUser(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote(null, PermissionEnum::AreaView->value),
        );
    }

    public function testStaffAreGrantedAModuleDeclaredPermissionTheirPositionHolds(): void
    {
        $position = (new Position())
            ->setName('Verifier')
            ->setPermissionValues(['verification.tiebreak']);
        $staff = (new User())
            ->setTeamRole(TeamRoleEnum::Staff)
            ->setPosition($position);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($staff, 'verification.tiebreak'),
        );
    }

    public function testStaffAreDeniedAModuleDeclaredPermissionOutsideTheirPosition(): void
    {
        $position = (new Position())
            ->setName('Ranger')
            ->setPermissions([PermissionEnum::AreaView]);
        $staff = (new User())
            ->setTeamRole(TeamRoleEnum::Staff)
            ->setPosition($position);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($staff, 'verification.tiebreak'),
        );
    }

    public function testAdminTiersHoldModuleDeclaredPermissionsByTierToo(): void
    {
        $admin = new User()->setTeamRole(TeamRoleEnum::Admin);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($admin, 'verification.tiebreak'),
        );
    }

    public function testItAbstainsOnAPermissionNoModuleDeclares(): void
    {
        $admin = new User()->setTeamRole(TeamRoleEnum::SuperAdmin);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->vote($admin, 'uninstalled.module.permission'),
        );
    }

    private function vote(?User $user, string $attribute): int
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $this->voter->vote($token, null, [$attribute]);
    }
}
