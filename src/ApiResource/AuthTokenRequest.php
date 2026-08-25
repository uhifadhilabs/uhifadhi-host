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
use ApiPlatform\Metadata\Post;
use Symfony\Component\Validator\Constraints as Assert;
use Uhifadhi\Api\State\AuthTokenProcessor;

/**
 * `POST /api/auth/token` — API-CONTRACT.md §2. The one endpoint reachable
 * without a token.
 *
 * A DTO, never an entity: nothing about {@see \Uhifadhi\Entity\User} belongs on
 * the wire, least of all in the request that has not yet proved who is asking.
 */
#[ApiResource(
    shortName: 'AuthToken',
    operations: [
        new Post(
            uriTemplate: '/auth/token',
            status: 200,
            description: 'Sign a field device in and mint a bearer token. Long-lived by design: a ranger cannot re-authenticate from the bush, so there is no refresh call.',
            output: AuthTokenResponse::class,
            processor: AuthTokenProcessor::class,
        ),
    ],
)]
final class AuthTokenRequest
{
    /**
     * The ranger's service number ("sl-0142"). An email address is accepted too,
     * for staff who have no service number — see
     * UserRepository::findOneByFieldIdentifier().
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    public string $rangerId = '';

    /** The account password. Never logged, never echoed back. */
    #[Assert\NotBlank]
    public string $passcode = '';

    /** The stable per-install UUID, so a single handset can be revoked alone. */
    #[Assert\Length(max: 64)]
    public ?string $deviceId = null;

    /** "Doria on Pixel 7a" — shown on the revoke screen, nothing more. */
    #[Assert\Length(max: 120)]
    public ?string $deviceName = null;
}
