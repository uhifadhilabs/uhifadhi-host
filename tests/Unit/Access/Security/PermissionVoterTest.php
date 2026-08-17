<?php

declare(strict_types=1);

namespace App\Tests\Unit\Access\Security;

use App\Access\Entity\Position;
use App\Access\Entity\User;
use App\Access\Enum\PermissionEnum;
use App\Access\Enum\TeamRoleEnum;
use App\Access\Security\PermissionVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

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
        $this->voter = new PermissionVoter();
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
        $staff = (new User())->setTeamRole(TeamRoleEnum::Staff);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($staff, PermissionEnum::AreaView->value),
        );
    }

    public function testAdminTiersHoldEveryPermissionEvenWithoutAPosition(): void
    {
        $tiers = [TeamRoleEnum::SuperAdmin, TeamRoleEnum::Admin];

        foreach ($tiers as $tier) {
            $user = (new User())->setTeamRole($tier);

            foreach (PermissionEnum::all() as $permission) {
                self::assertSame(
                    VoterInterface::ACCESS_GRANTED,
                    $this->vote($user, $permission->value),
                    sprintf('%s must hold %s by tier', $tier->name, $permission->value),
                );
            }
        }
    }

    public function testItAbstainsOnAnAttributeThatIsNotAPermission(): void
    {
        $user = (new User())->setTeamRole(TeamRoleEnum::SuperAdmin);

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

    private function vote(?User $user, string $attribute): int
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $this->voter->vote($token, null, [$attribute]);
    }
}
