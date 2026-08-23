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

namespace Uhifadhi\Factory;

use Uhifadhi\Entity\Position;
use Uhifadhi\Enum\PermissionEnum;
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
