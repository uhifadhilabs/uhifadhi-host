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

namespace Uhifadhi\Overview;

use Uhifadhi\Entity\AreaOfInterest;

/**
 * THE SEAM A MODULE PUTS A TILE IN THE HOST'S RIGHT-NOW STRIP THROUGH.
 *
 * "Right now" and "Needs attention" are the two widgets a page like this always
 * grows, and they are exactly the two that would become a hard-coded list of
 * every module the product has ever shipped. So the host draws neither: it lays
 * out and orders CONTRIBUTED PARTS, and knows nothing about what they count.
 *
 * The same tiles are what the duty board draws at board density — one set of
 * numbers, two densities, so a count cannot read one way in the strip and
 * another on the wall.
 *
 * Returning `[]` is a legitimate answer. A module with nothing to say puts no
 * tile in the row; it does not put a tile reading 0 there, which would claim it
 * measured and found none.
 *
 * Tagged explicitly at both ends, for the reason
 * {@see OverviewContributorInterface} spells out.
 */
interface NowTileProviderInterface
{
    public const string TAG = 'uhifadhi.overview.now_tile';

    /** The slug of the module these tiles belong to; asked only where it is installed. */
    public function moduleSlug(): string;

    /**
     * @return list<NowTile>
     */
    public function nowTilesFor(AreaOfInterest $area, \DateTimeImmutable $now): array;
}
