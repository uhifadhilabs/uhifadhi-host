<?php

declare(strict_types=1);

namespace App\Access\Command;

use App\Access\Entity\Position;
use App\Access\Entity\User;
use App\Access\Enum\PermissionEnum;
use App\Access\Enum\TeamRoleEnum;
use App\Access\Repository\PositionRepository;
use App\Access\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Seeds the demo accounts (Super Admin / Admin / Manager / two Staff) and their positions with a
 * single shared password taken from %env(DEMO_PASSWORD)% and hashed here — mirroring vivutio's
 * fixture pattern, but as an idempotent, non-destructive command: unlike doctrine:fixtures:load it
 * never purges, so it is safe against the dev database's real Hansen/WDPA data. These are testing
 * logins, not for prod; provision real prod accounts with app:user:create.
 */
#[AsCommand(
    name: 'app:demo:seed',
    description: 'Idempotently seed the demo accounts + positions (password from DEMO_PASSWORD).',
)]
final class SeedDemoCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly PositionRepository $positions,
        private readonly UserPasswordHasherInterface $hasher,
        #[Autowire('%env(DEMO_PASSWORD)%')]
        private readonly string $demoPassword,
        // The Super Admin can impersonate anyone, so it gets its own distinct password.
        #[Autowire('%env(DEMO_SUPER_ADMIN_PASSWORD)%')]
        private readonly string $superAdminPassword,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach (['DEMO_PASSWORD' => $this->demoPassword, 'DEMO_SUPER_ADMIN_PASSWORD' => $this->superAdminPassword] as $name => $value) {
            if ('' === trim($value) || 'changeme-in-env-local' === $value) {
                $io->error(\sprintf('Set a real %s in .env.local before seeding demo accounts.', $name));

                return Command::INVALID;
            }
        }

        // Positions first — the Staff demo accounts reference them.
        $ranger = $this->ensurePosition('Ranger', [PermissionEnum::AreaView, PermissionEnum::IngestionRun]);
        $analyst = $this->ensurePosition('Analyst', [PermissionEnum::AreaView, PermissionEnum::ModuleView, PermissionEnum::ModuleCreate]);

        $created = 0;
        $created += $this->ensureUser('superadmin@ncaa.uhifadhi.com', 'Sofia', 'Marwa', TeamRoleEnum::SuperAdmin, password: $this->superAdminPassword);
        $created += $this->ensureUser('admin@ncaa.uhifadhi.com', 'Amina', 'Hassan', TeamRoleEnum::Admin);
        $created += $this->ensureUser('manager@ncaa.uhifadhi.com', 'Joseph', 'Kimaro', TeamRoleEnum::Manager);
        $created += $this->ensureUser('ranger@ncaa.uhifadhi.com', 'Neema', 'Kileo', TeamRoleEnum::Staff, $ranger);
        $created += $this->ensureUser('analyst@ncaa.uhifadhi.com', 'Baraka', 'Mushi', TeamRoleEnum::Staff, $analyst);

        $this->em->flush();

        $io->success(\sprintf('Demo seed complete — %d account(s) created, the rest already existed.', $created));
        $io->note('Log in with any demo email and the DEMO_PASSWORD value.');

        return Command::SUCCESS;
    }

    /**
     * @param list<PermissionEnum> $permissions
     */
    private function ensurePosition(string $name, array $permissions): Position
    {
        $position = $this->positions->findOneBy(['name' => $name]);
        if (null === $position) {
            $position = (new Position())->setName($name)->setPermissions($permissions);
            $this->em->persist($position);
        }

        return $position;
    }

    private function ensureUser(string $email, string $firstName, string $lastName, TeamRoleEnum $tier, ?Position $position = null, ?string $password = null): int
    {
        if (null !== $this->users->findOneByEmail($email)) {
            return 0;
        }

        $user = (new User())
            ->setEmail($email)
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setTeamRole($tier)
            ->setPosition($position)
            ->setVerified(true);
        $user->setPassword($this->hasher->hashPassword($user, $password ?? $this->demoPassword));

        $this->em->persist($user);

        return 1;
    }
}
