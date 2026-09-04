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
use Uhifadhi\Api\State\MeProvider;

/**
 * `GET /api/me` — API-CONTRACT.md §2A: who this token belongs to, and what the account may do.
 *
 * The same facts sign-in already answered, asked again — because months pass between sign-ins and
 * a permission granted in the web app has to reach the phone without a sign-out, a re-install or
 * any ceremony. The app calls it at the start of every sync run and from the two "check again"
 * controls the design gives a ranger who has been refused.
 *
 * It lives in the HOST for the same reason `/api/areas/mine` does: the account, its tier and its
 * position are host entities, and the permission catalogue is the host's. The host still learns
 * nothing about patrols — it sends the account's whole permission set and the app reads the one
 * member it understands.
 */
#[ApiResource(
    shortName: 'Me',
    operations: [
        new Get(
            uriTemplate: '/me',
            description: 'The signed-in account and the permissions it holds, re-read on every sync.',
            provider: MeProvider::class,
        ),
    ],
)]
final class Me
{
    /**
     * Hand-built arrays, as everywhere on this wire: these key names are an external contract and
     * must not drift with serializer configuration.
     *
     * @param array{id: string, name: string, role: string} $ranger
     * @param list<string>                                  $permissions every catalogue value this
     *                                                                   account holds; an EMPTY
     *                                                                   array is a refusal, and the
     *                                                                   field is never omitted
     */
    public function __construct(
        public array $ranger = ['id' => '', 'name' => '', 'role' => ''],
        public array $permissions = [],
    ) {
    }
}
