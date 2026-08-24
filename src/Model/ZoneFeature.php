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

namespace Uhifadhi\Model;

/**
 * One zone as it comes out of a GeoJSON file: a name and a normalised MultiPolygon,
 * validated for shape but not yet for the spatial invariant and not yet persisted.
 */
final readonly class ZoneFeature
{
    public function __construct(
        public string $name,
        public string $geomJson,
    ) {
    }
}
