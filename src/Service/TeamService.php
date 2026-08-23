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

namespace Uhifadhi\Service;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Entity\Position;
use Uhifadhi\Entity\User;
use Uhifadhi\Enum\TeamRoleEnum;
use Uhifadhi\Repository\PositionRepository;
use Uhifadhi\Repository\UserRepository;

/**
 * Team administration for the single authority: the roster, the position catalogue, and the
 * mutations behind the /team screen. Managing tiers (Super Admin / Admin / Manager) hold every
 * permission by tier, so positions only ever apply to Staff — {@see canManage()} reflects that.
 */
final readonly class TeamService
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private PositionRepository $positions,
    ) {
    }

    /**
     * Everyone, ordered by tier (Super Admin → Admin → Manager → Staff) then name — the roster order.
     *
     * @return list<User>
     */
    public function members(): array
    {
        $members = $this->users->findBy([], ['lastName' => 'ASC', 'firstName' => 'ASC']);

        // Tier is an enum, so sort in PHP against a fixed rank rather than in DQL.
        $rank = [
            TeamRoleEnum::SuperAdmin->value => 0,
            TeamRoleEnum::Admin->value => 1,
            TeamRoleEnum::Manager->value => 2,
            TeamRoleEnum::Staff->value => 3,
        ];
        usort($members, static fn (User $a, User $b): int => $rank[$a->getTeamRole()->value] <=> $rank[$b->getTeamRole()->value]);

        return $members;
    }

    /**
     * @return list<Position>
     */
    public function positions(): array
    {
        return $this->positions->all();
    }

    /**
     * @param list<string> $permissionValues catalogue-validated values — core
     *                                       and module-declared permissions alike
     */
    public function createPosition(string $name, array $permissionValues): Position
    {
        $position = new Position()
            ->setName($name)
            ->setPermissionValues($permissionValues);

        $this->em->persist($position);
        $this->em->flush();

        return $position;
    }

    /**
     * @param list<string> $permissionValues catalogue-validated values — core
     *                                       and module-declared permissions alike
     */
    public function updatePosition(Position $position, string $name, array $permissionValues): void
    {
        // A locked position keeps its label (reserved); its permissions may still change.
        if (!$position->isLocked()) {
            $position->setName($name);
        }
        $position->setPermissionValues($permissionValues);

        $this->em->flush();
    }

    public function deletePosition(Position $position): void
    {
        // Detach every holder first so the FK clears, then remove the row.
        foreach ($this->users->findBy(['position' => $position]) as $holder) {
            $holder->setPosition(null);
        }
        $this->em->remove($position);
        $this->em->flush();
    }

    public function assignPosition(User $user, ?Position $position): void
    {
        $user->setPosition($position);
        $this->em->flush();
    }

    /**
     * Whether a position can be assigned to this member. Only Staff hold positions — managing
     * tiers already hold every permission by tier, so a position on them would be meaningless.
     */
    public function canManage(User $target): bool
    {
        return !$target->getTeamRole()->canManageContent();
    }
}
