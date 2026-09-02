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
 *
 * THE FIRST THREE ARE READINGS OF THE AREA — what the ecosystem is doing, what
 * people are doing to it, and what lives in it. They were the whole taxonomy
 * when the product was an observatory, and they have no room at all for the
 * rangers' OWN work: patrols, incidents, rosters. Those were filed under
 * "pressure", which reads as human pressure ON the ecosystem and so says the
 * opposite of what a patrol is.
 *
 * OPERATIONS is that fourth reading, and after the operational pivot it is the
 * one most modules belong to — so it leads the catalogue rather than trailing
 * the three it was added after.
 */
enum ModuleCategory: string
{
    case Operations = 'operations';
    case Flux = 'flux';
    case Pressure = 'pressure';
    case Biodiversity = 'biodiversity';

    public function label(): string
    {
        return match ($this) {
            self::Operations => 'Operations',
            self::Flux => 'Flux',
            self::Pressure => 'Pressure',
            self::Biodiversity => 'Biodiversity',
        };
    }
}
