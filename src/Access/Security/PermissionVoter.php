<?php

declare(strict_types=1);

namespace App\Access\Security;

use App\Access\Entity\User;
use App\Access\Enum\PermissionEnum;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decides a granular permission (e.g. `is_granted('area.create')`) from the user's tier and
 * position. Super Admin / Admin / Manager hold every permission by tier; a Staff user holds
 * exactly the permissions of their assigned {@see \App\Access\Entity\Position}. Attributes that
 * are not a {@see PermissionEnum} value are none of this voter's business — it abstains so the
 * role voters can decide them.
 *
 * @extends Voter<string, mixed>
 */
final class PermissionVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return null !== PermissionEnum::tryFrom($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $permission = PermissionEnum::tryFrom($attribute);
        if (null === $permission) {
            return false;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // Super Admin / Admin / Manager hold every permission by tier.
        if ($user->getTeamRole()->canManageContent()) {
            return true;
        }

        // Staff: exactly their position's permissions.
        $position = $user->getPosition();

        return null !== $position && $position->hasPermission($permission);
    }
}
