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

/**
 * The sign-in response — API-CONTRACT.md §2.
 *
 * Documentation-shaped: the processor writes the JSON itself (see
 * {@see \Uhifadhi\Api\State\AuthTokenProcessor}), because the field names here
 * are an external contract and must not drift with serializer configuration.
 * This class is what OpenAPI shows a reader.
 */
final readonly class AuthTokenResponse
{
    /**
     * @param array{id: string, name: string} $ranger who the token belongs to,
     *                                                as the app displays them
     */
    public function __construct(
        public string $token,
        public string $expiresAt,
        public array $ranger,
    ) {
    }
}
