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
 * The taxonomy a module belongs to in the "add a module" catalogue (from the design's
 * category filter pills).
 */
enum ModuleCategory: string
{
    case Flux = 'flux';
    case Pressure = 'pressure';
    case Biodiversity = 'biodiversity';

    public function label(): string
    {
        return match ($this) {
            self::Flux => 'Flux',
            self::Pressure => 'Pressure',
            self::Biodiversity => 'Biodiversity',
        };
    }
}
