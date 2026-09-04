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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Uhifadhi\Entity\Department;
use Uhifadhi\Entity\Position;
use Uhifadhi\Entity\User;
use Uhifadhi\Enum\TeamRoleEnum;
use Uhifadhi\Repository\DepartmentRepository;
use Uhifadhi\Repository\UserRepository;

/**
 * Team administration for the single authority: the roster, the position catalogue, and the
 * mutations behind the /team surface. Managing tiers (Super Admin / Admin / Manager) hold every
 * permission by tier, so positions only ever apply to Staff — {@see canManage()} reflects that.
 *
 * It is also the team surface's DATA GATHERER. Six widgets render on one page and five of them
 * are the same list laid out five ways, so everything is gathered ONCE, in {@see context()}:
 * three queries for the whole page rather than three per widget, and no partial that quietly
 * queries — a partial that queries is a partial nobody can move to another surface.
 *
 * The structure every positions widget reads is {@see bands()}: the departments in order, each
 * carrying its own positions, with UNFILED last. Banding is not a convenience — it IS the
 * ruling. A position's name is unique inside its department only, so a position must never be
 * shown outside the department that owns it, and a create affordance must never exist outside a
 * band. Five directions, one structure; the difference between them is only how a band is drawn.
 */
final readonly class TeamService
{
    /**
     * The band key of the holding pen. Not a department and never rendered as one — it sorts
     * last, is drawn dashed, and its one real action is being filed under a real department.
     */
    public const string UNFILED = 'unfiled';

    /**
     * How many department hues the palette carries. A department is user-created and has no
     * colour of its own, so it gets one deterministically from its id — the same department is
     * the same colour on every widget, in every session, for every person. A colour NEVER
     * travels alone: every mark that uses one also carries the department's name, because a hue
     * that repeats after six departments must not be the only thing telling two of them apart.
     */
    public const int TONES = 6;

    /**
     * The alphabet a generated password is drawn from. The password is handed over by hand —
     * read aloud across a desk or typed off a note — so every character a reader confuses with
     * another (0/O, 1/l/I) is out. Length, not alphabet size, carries the entropy.
     */
    private const string ALPHABET = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private DepartmentRepository $departments,
        private UserPasswordHasherInterface $hasher,
    ) {
    }

    /**
     * The partial contract, in one array: exactly the variables every
     * templates/team/_w_<id>.html.twig receives.
     *
     * `scope` and `rail` are the two widgets that hold a selection (B's chips, E's rail). They
     * arrive as band keys from the query string rather than as client state, so a scoped list is
     * a URL somebody can send, and it survives a reload — which is the whole reason those two
     * directions are legible: you can always see which department's list you are in.
     *
     * @param string|null $scope B's chosen band key, or null/'all' for every department at once
     * @param string|null $rail  E's chosen band key; null opens on the first band
     *
     * @return array{
     *     members: list<User>,
     *     positions: list<Position>,
     *     departments: list<Department>,
     *     bands: list<array{key: string, name: string, tone: string, department: Department|null, positions: list<Position>, holders: int}>,
     *     holders: array<int, int>,
     *     twins: list<string>,
     *     scope: string,
     *     rail: string,
     * }
     */
    public function context(?string $scope = null, ?string $rail = null): array
    {
        $members = $this->members();
        $positions = $this->positionsInBandOrder();
        $departments = $this->departments->findAllOrdered();
        $holders = self::holders($members);
        $bands = self::bands($departments, $positions, $holders);
        $keys = array_column($bands, 'key');

        return [
            'members' => $members,
            'positions' => $positions,
            'departments' => $departments,
            'bands' => $bands,
            'holders' => $holders,
            'twins' => self::twins($positions),
            // An unknown key is "all" rather than an error: it arrives in a URL.
            'scope' => null !== $scope && \in_array($scope, $keys, true) ? $scope : 'all',
            'rail' => null !== $rail && \in_array($rail, $keys, true) ? $rail : ($keys[0] ?? self::UNFILED),
        ];
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
     * Every position, in BAND ORDER: by department name, then by its own name, with the unfiled
     * ones last. It is the order the grouped table, the flat list and the assign dropdown all
     * read in, so "Ecology / Analyst" and "Protection Service / Analyst" are never adjacent by
     * accident.
     *
     * @return list<Position>
     */
    public function positionsInBandOrder(): array
    {
        /** @var list<Position> $positions */
        $positions = $this->em->createQueryBuilder()
            ->select('p', 'd')
            ->from(Position::class, 'p')
            ->leftJoin('p.department', 'd')
            ->orderBy('d.name', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        // Doctrine sorts NULL departments first on Postgres; unfiled belongs last, everywhere.
        usort($positions, static fn (Position $a, Position $b): int => [
            null === $a->getDepartment() ? 1 : 0, (string) $a->getDepartment()?->getName(), (string) $a->getName(),
        ] <=> [
            null === $b->getDepartment() ? 1 : 0, (string) $b->getDepartment()?->getName(), (string) $b->getName(),
        ]);

        return $positions;
    }

    /**
     * The org-wide department list. A position sits in at most one; a member's department
     * follows their position.
     *
     * @return list<Department>
     */
    public function departments(): array
    {
        return $this->departments->findAllOrdered();
    }

    /**
     * Whether this department may take a position by this name. THE uniqueness rule, asked in
     * the app's own words so a clash is a sentence on the screen rather than a 500 from the
     * index behind it — and asked against the chosen department ONLY, which is exactly why
     * every create affordance names a department before it takes a name.
     *
     * `$except` is the position being renamed: a name is never a clash with itself.
     */
    public function nameIsFree(?Department $department, string $name, ?Position $except = null): bool
    {
        $query = $this->em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Position::class, 'p')
            ->andWhere('LOWER(p.name) = LOWER(:name)')
            ->setParameter('name', trim($name));

        if (null === $department) {
            $query->andWhere('p.department IS NULL');
        } else {
            $query->andWhere('p.department = :department')->setParameter('department', $department);
        }
        if (null !== $except && null !== $except->getId()) {
            $query->andWhere('p.id <> :except')->setParameter('except', $except->getId());
        }

        return 0 === (int) $query->getQuery()->getSingleScalarResult();
    }

    /**
     * Create a position IN A DEPARTMENT. The department is a parameter and not an afterthought:
     * every surface that creates one states it first (a band you type inside, a card's own
     * button, a required field one), because the name it is about to take is only unique there.
     *
     * @param list<string> $permissionValues catalogue-validated values — core
     *                                       and module-declared permissions alike
     */
    public function createPosition(string $name, array $permissionValues, ?Department $department = null): Position
    {
        $position = new Position()
            ->setName(trim($name))
            ->setPermissionValues($permissionValues)
            ->setDepartment($department);

        $this->em->persist($position);
        $this->em->flush();

        return $position;
    }

    /**
     * Save a position whole: its name, its permissions and the department it belongs to. The
     * department is part of the same save because moving re-scopes the name — the caller has
     * already asked {@see nameIsFree()} against the TARGET department, so the two land together
     * or not at all. Holders follow by inheritance; none of their rows are touched.
     *
     * @param list<string> $permissionValues catalogue-validated values — core
     *                                       and module-declared permissions alike
     */
    public function updatePosition(
        Position $position,
        string $name,
        array $permissionValues,
        ?Department $department = null,
    ): void {
        // A locked position keeps its label (reserved) and its department; permissions may change.
        if (!$position->isLocked()) {
            $position->setName(trim($name));
            $position->setDepartment($department);
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

    /**
     * File an UNFILED position under a real department — the holding pen's one real action.
     * Organizational only: the position keeps every permission it had, and its holders move with
     * it by inheritance. It is not a general "change the department" control, because moving a
     * position between two departments would silently re-scope its name.
     */
    public function filePosition(Position $position, Department $department): void
    {
        $position->setDepartment($department);
        $this->em->flush();
    }

    /**
     * Whether this email is free to take. Asked in the app's own words so a duplicate is a
     * sentence on the screen rather than a 500 from the unique index behind it.
     */
    public function emailIsFree(string $email): bool
    {
        return null === $this->users->findOneByEmail(trim($email));
    }

    /**
     * Create a member and hand back the password they were given. There is NO mail flow in this
     * deployment, so an account is verified from birth and its password is generated here and
     * shown to the creating admin exactly once — the handover is out of band, by design.
     *
     * A position is only ever kept for a tier that does not already hold everything by tier
     * ({@see canManage()}); on a managing tier it would be meaningless, so it is dropped rather
     * than stored as a decoration.
     *
     * @return array{user: User, password: string}
     */
    public function createMember(
        string $email,
        string $firstName,
        string $lastName,
        TeamRoleEnum $tier,
        ?Position $position = null,
    ): array {
        $password = self::generatePassword();

        $user = new User()
            ->setEmail(trim($email))
            ->setFirstName(trim($firstName))
            ->setLastName(trim($lastName))
            ->setTeamRole($tier)
            ->setPosition($tier->canManageContent() ? null : $position)
            ->setVerified(true);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        return ['user' => $user, 'password' => $password];
    }

    /**
     * Give an EXISTING member a new password, and hand it back to be shown once.
     *
     * The other half of a deployment with no mail flow. Creation shows the password once, which
     * is correct and is also the whole failure mode: an admin who closed that page, or who
     * inherited an account somebody else made, has no way back to it and no "forgot password"
     * mail to fall back on. Same alphabet, same show-once rule, same out-of-band handover — this
     * is the creation act performed again on an account that already exists.
     *
     * Every session the member currently holds keeps working; it is the PASSWORD that changed,
     * and the old one stops authenticating immediately.
     */
    public function regeneratePassword(User $user): string
    {
        $password = self::generatePassword();
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->flush();

        return $password;
    }

    /** A one-off password for a new member — see {@see self::ALPHABET} for why it reads the way it does. */
    public static function generatePassword(int $length = 20): string
    {
        $last = \strlen(self::ALPHABET) - 1;
        $password = '';
        for ($i = 0; $i < $length; ++$i) {
            $password .= self::ALPHABET[random_int(0, $last)];
        }

        return $password;
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

    /** The band key a department is drawn under — its uuid, so nothing depends on a name. */
    public static function bandKey(?Department $department): string
    {
        return null !== $department ? (string) $department->getUuidString() : self::UNFILED;
    }

    /**
     * A position written department-first: "Ecology / Analyst". THE cross-cutting rule — used
     * wherever the department is not already stated by where the name sits (the People roster,
     * the assign dropdown, the flat list, a flash message), because a bare "Analyst" names two
     * different positions with two different permission sets.
     */
    public static function qualified(Position $position): string
    {
        return \sprintf(
            '%s / %s',
            $position->getDepartment()?->getName() ?? 'Unfiled',
            (string) $position->getName(),
        );
    }

    /**
     * The departments in order, each carrying its own positions, with UNFILED last. Every
     * department asked about gets a band — an empty department is a real answer (it is what a
     * staffing gap looks like), never a missing key a template has to guard — and the unfiled
     * band appears only when something is actually unfiled, because a permanently visible
     * holding pen reads as a department.
     *
     * @param list<Department> $departments
     * @param list<Position>   $positions
     * @param array<int, int>  $holders
     *
     * @return list<array{key: string, name: string, tone: string, department: Department|null, positions: list<Position>, holders: int}>
     */
    public static function bands(array $departments, array $positions, array $holders): array
    {
        $byKey = [];
        foreach ($departments as $index => $department) {
            $byKey[self::bandKey($department)] = [
                'key' => self::bandKey($department),
                'name' => (string) $department->getName(),
                'tone' => 'd'.($index % self::TONES),
                'department' => $department,
                'positions' => [],
                'holders' => 0,
            ];
        }

        $unfiled = [
            'key' => self::UNFILED,
            'name' => 'Unfiled',
            'tone' => 'un',
            'department' => null,
            'positions' => [],
            'holders' => 0,
        ];

        foreach ($positions as $position) {
            $key = self::bandKey($position->getDepartment());
            $held = $holders[(int) $position->getId()] ?? 0;
            if (self::UNFILED === $key) {
                $unfiled['positions'][] = $position;
                $unfiled['holders'] += $held;

                continue;
            }
            if (!isset($byKey[$key])) {
                continue; // a department the ordered list did not return; not this screen's problem
            }
            $byKey[$key]['positions'][] = $position;
            $byKey[$key]['holders'] += $held;
        }

        $bands = array_values($byKey);
        if ([] !== $unfiled['positions']) {
            $bands[] = $unfiled;
        }

        return $bands;
    }

    /**
     * How many people hold each position, by position id. Counted from the roster already in
     * hand rather than with a second query — and every position the roster never mentions
     * simply is not a key, which the templates read through a default of 0.
     *
     * @param list<User> $members
     *
     * @return array<int, int>
     */
    public static function holders(array $members): array
    {
        $counts = [];
        foreach ($members as $member) {
            $id = $member->getPosition()?->getId();
            if (null !== $id) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * The names held by MORE THAN ONE position — lower-cased, because "Analyst" and "analyst"
     * are the same word to a reader.
     *
     * This is what the "twin name" flag is rendered from, and it is rendered from real data
     * only: the flag says "there is another position with this name, and it is NOT this one",
     * which is a lie on a name that happens to be unique. Two departments sharing a word is
     * expected and legal — the flag exists so a reader never merges the two rows, not to warn
     * anybody off.
     *
     * @param list<Position> $positions
     *
     * @return list<string>
     */
    public static function twins(array $positions): array
    {
        $seen = [];
        foreach ($positions as $position) {
            $name = mb_strtolower(trim((string) $position->getName()));
            $seen[$name] = ($seen[$name] ?? 0) + 1;
        }

        return array_keys(array_filter($seen, static fn (int $count): bool => $count > 1));
    }
}
