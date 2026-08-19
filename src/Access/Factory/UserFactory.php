<?php

declare(strict_types=1);

namespace Uhifadhi\Access\Factory;

use Uhifadhi\Access\Entity\User;
use Uhifadhi\Access\Enum\TeamRoleEnum;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return User::class;
    }

    protected function defaults(): array
    {
        return [
            'email' => self::faker()->unique()->email(),
            'firstName' => self::faker()->firstName(),
            'lastName' => self::faker()->lastName(),
            // A placeholder hash-less value; tests that actually sign in overwrite this
            // with a real hash (see LoginTest), mirroring the seed command's hashing.
            'password' => 'placeholder',
            'teamRole' => TeamRoleEnum::Staff,
            'verified' => true,
        ];
    }

    public function superAdmin(): static
    {
        return $this->with(['teamRole' => TeamRoleEnum::SuperAdmin]);
    }

    public function admin(): static
    {
        return $this->with(['teamRole' => TeamRoleEnum::Admin]);
    }

    public function manager(): static
    {
        return $this->with(['teamRole' => TeamRoleEnum::Manager]);
    }
}
