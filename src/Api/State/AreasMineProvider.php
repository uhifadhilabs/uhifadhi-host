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

namespace Uhifadhi\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Uhifadhi\Api\ContractFormat;
use Uhifadhi\ApiResource\AreasMine;
use Uhifadhi\Repository\AreaOfInterestRepository;
use Uhifadhi\Repository\UserRepository;

/**
 * Builds the field app's offline cache — API-CONTRACT.md §3.
 *
 * @implements ProviderInterface<AreasMine>
 */
final class AreasMineProvider implements ProviderInterface
{
    public function __construct(
        private readonly AreaOfInterestRepository $areas,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AreasMine
    {
        /*
         * The roster is read once and shared by every area. uhifadhi is a
         * single-authority deployment — one organisation, no party isolation —
         * so "the team" is the same list of people whichever area they are
         * standing in; see the note on User.
         */
        $team = array_map(
            static fn (\Uhifadhi\Entity\User $user): array => [
                'id' => ContractFormat::rangerId($user),
                'name' => $user->getFullName(),
            ],
            $this->users->findRoster(),
        );

        $areas = [];
        foreach ($this->areas->findFieldSummaries() as $area) {
            $areas[] = [
                'id' => $area['uuid'],
                'name' => $area['name'],
                'areaKm2' => $area['areaKm2'],
                /*
                 * Empty, and honestly so: uhifadhi has no station entity yet.
                 * Inventing stations from the free-text station names typed on
                 * past patrols would hand the app a list of guesses dressed as
                 * a register, with positions we do not have. See the deviation
                 * note in API-CONTRACT.md.
                 */
                'stations' => [],
                'team' => $team,
                'boundary' => self::geoJson($area['boundary']),
            ];
        }

        return new AreasMine($areas);
    }

    /**
     * PostGIS hands back GeoJSON as text; the contract wants it as an object,
     * already in lon/lat (RFC 7946), which is the order PostGIS emits.
     *
     * @return array<string, mixed>|null
     */
    private static function geoJson(string $text): ?array
    {
        if ('' === $text) {
            return null;
        }

        $decoded = json_decode($text, true);

        /** @var array<string, mixed>|null */
        return \is_array($decoded) ? $decoded : null;
    }
}
