<?php

declare(strict_types=1);

namespace Uhifadhi\Access\Factory;

use Uhifadhi\Access\Entity\Position;
use Uhifadhi\Access\Enum\PermissionEnum;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Position>
 */
final class PositionFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Position::class;
    }

    protected function defaults(): array
    {
        return [
            'name' => self::faker()->unique()->jobTitle(),
            'locked' => false,
        ];
    }

    /**
     * @param list<PermissionEnum> $permissions
     */
    public function withPermissions(array $permissions): static
    {
        return $this->afterInstantiate(static function (Position $position) use ($permissions): void {
            $position->setPermissions($permissions);
        });
    }
}
