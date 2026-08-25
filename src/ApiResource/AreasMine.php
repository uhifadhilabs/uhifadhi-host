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

namespace Uhifadhi\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use Uhifadhi\Api\State\AreasMineProvider;

/**
 * `GET /api/areas/mine` — API-CONTRACT.md §3: everything the app caches at
 * sign-in so it can work with no network afterwards.
 *
 * This resource lives in the HOST, not in the patrol module, and deliberately:
 * areas, their boundaries and the staff roster are host entities. A module that
 * served them would be answering for data it does not own, and every other
 * module would then need its own copy of the same endpoint.
 */
#[ApiResource(
    shortName: 'AreasMine',
    operations: [
        new Get(
            uriTemplate: '/areas/mine',
            description: 'The areas, stations, roster and boundaries this account can work in.',
            provider: AreasMineProvider::class,
        ),
    ],
)]
final class AreasMine
{
    /**
     * Hand-built associative arrays rather than nested DTOs: these key names
     * are an external contract, and an array says exactly what goes on the wire
     * without depending on serializer naming conventions.
     *
     * @param list<array{
     *     id: string,
     *     name: string,
     *     areaKm2: float,
     *     stations: list<array{id: string, name: string, position: array{lat: float, lon: float}}>,
     *     team: list<array{id: string, name: string}>,
     *     boundary: array<string, mixed>|null
     * }> $areas
     */
    public function __construct(
        public array $areas = [],
    ) {
    }
}
