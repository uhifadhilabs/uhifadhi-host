<?php

declare(strict_types=1);

namespace App\Access\Command;

use App\Access\Entity\User;
use App\Access\Enum\TeamRoleEnum;
use App\Access\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates a staff account and prompts (hidden) for its password — used to seed the
 * initial Super Admin so someone can log in and impersonate. On prod:
 *   kamal app exec "php bin/console app:user:create ... --role=super-admin"
 */
#[AsCommand(
    name: 'app:user:create',
    description: 'Create a user account (prompts for the password).',
)]
final class CreateUserCommand extends Command
{
    /** CLI role tokens → tier. */
    private const array ROLES = [
        'super-admin' => TeamRoleEnum::SuperAdmin,
        'admin' => TeamRoleEnum::Admin,
        'manager' => TeamRoleEnum::Manager,
        'staff' => TeamRoleEnum::Staff,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email (also the login identifier)')
            ->addArgument('firstName', InputArgument::REQUIRED, 'First name')
            ->addArgument('lastName', InputArgument::REQUIRED, 'Last name')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'super-admin | admin | manager | staff', 'staff');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');
        $firstName = $input->getArgument('firstName');
        $lastName = $input->getArgument('lastName');
        $roleToken = $input->getOption('role');
        if (!\is_string($email) || !\is_string($firstName) || !\is_string($lastName) || !\is_string($roleToken)) {
            $io->error('email, firstName, lastName and --role must all be strings.');

            return Command::INVALID;
        }

        $tier = self::ROLES[$roleToken] ?? null;
        if (null === $tier) {
            $io->error(\sprintf('Unknown role "%s". Use one of: %s.', $roleToken, implode(', ', array_keys(self::ROLES))));

            return Command::INVALID;
        }

        if (null !== $this->users->findOneByEmail($email)) {
            $io->error(\sprintf('A user with email "%s" already exists.', $email));

            return Command::FAILURE;
        }

        $password = $io->askHidden('Password (input hidden)');
        if (!\is_string($password) || '' === trim($password)) {
            $io->error('A non-empty password is required.');

            return Command::INVALID;
        }

        $user = (new User())
            ->setEmail($email)
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setTeamRole($tier)
            ->setVerified(true);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        $io->success(\sprintf('Created %s (%s) as %s.', $user->getFullName(), $user->getUserIdentifier(), $tier->label()));

        return Command::SUCCESS;
    }
}
