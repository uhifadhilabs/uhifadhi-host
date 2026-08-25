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

namespace Uhifadhi\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Uhifadhi\Service\ApiTokenManager;

/**
 * Authenticates the field app: `Authorization: Bearer <token>`, checked against
 * the {@see \Uhifadhi\Entity\ApiToken} rows. Stateless — no session is started
 * and none is read, so every request stands on its own, which is what an app
 * that syncs in bursts from a truck actually needs.
 *
 * It is also the firewall's entry point. Without one, a request with NO token
 * would be refused as 403 by the access listener; the contract distinguishes
 * 401 ("sign in again") from 403 ("not permitted to record") and the app shows
 * different things for each, so the difference has to be real.
 */
final class ApiTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    private const string SCHEME = 'Bearer ';

    public function __construct(
        private readonly ApiTokenManager $tokens,
    ) {
    }

    /**
     * Only claims requests that actually present a bearer token. A request
     * without one falls through unauthenticated to {@see start()}, which is how
     * "no token" becomes 401 rather than 403.
     */
    public function supports(Request $request): bool
    {
        return null !== self::bearer($request);
    }

    public function authenticate(Request $request): Passport
    {
        $presented = self::bearer($request)
            ?? throw new CustomUserMessageAuthenticationException('No bearer token.');

        $token = $this->tokens->find($presented)
            // Unknown, revoked and expired are one message on purpose — see
            // ApiTokenManager::find(). The app's reaction is the same for all
            // three: stop, and ask the ranger to sign in again.
            ?? throw new CustomUserMessageAuthenticationException('The token is not valid. Sign in again.');

        $this->tokens->touch($token);

        $user = $token->getOwner();

        // SelfValidating: the bearer token IS the proof. There is no password to
        // re-check, and the user is already in hand, so the loader hands it back
        // rather than sending the provider on a second lookup.
        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), static fn (): \Symfony\Component\Security\Core\User\UserInterface => $user),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Nothing to do: let the request continue to the controller.
        return null;
    }

    /**
     * The 401 body is left to {@see \Uhifadhi\EventListener\ApiErrorListener},
     * which gives every /api failure the contract's `{code, message, retryable,
     * details}` shape — so the error document is written in exactly one place.
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new Response(null, Response::HTTP_UNAUTHORIZED);
    }

    /** No credentials at all — same answer, same shape. */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new Response(null, Response::HTTP_UNAUTHORIZED);
    }

    /** The token from `Authorization: Bearer <token>`, or null if absent/malformed. */
    private static function bearer(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');

        if (null === $header || !str_starts_with($header, self::SCHEME)) {
            return null;
        }

        $token = trim(substr($header, \strlen(self::SCHEME)));

        return '' === $token ? null : $token;
    }
}
