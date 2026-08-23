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

namespace Uhifadhi\Enum;

/**
 * How far along a module is: a {@see Live} module rendering real data
 * (Forest loss); or a {@see Template} module scaffolded until its ingestion lands. Drives the
 * status chip on the module rows and catalogue cards.
 */
enum ModuleStatus: string
{
    case Live = 'live';
    case Template = 'template';

    public function label(): string
    {
        return match ($this) {
            self::Live => 'live',
            self::Template => 'template',
        };
    }
}
