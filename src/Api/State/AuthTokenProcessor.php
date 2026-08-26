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
use ApiPlatform\State\ProcessorInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Uhifadhi\Api\ApiProblemException;
use Uhifadhi\Api\ContractFormat;
use Uhifadhi\ApiResource\AuthTokenRequest;
use Uhifadhi\Entity\User;
use Uhifadhi\Repository\UserRepository;
use Uhifadhi\Service\ApiTokenManager;

/**
 * Signs a field device in — API-CONTRACT.md §2.
 *
 * Returns a Response rather than an object to serialize. api-platform passes a
 * Response straight through both SerializeProcessor and RespondProcessor (see
 * their first lines), which is what lets this endpoint guarantee the contract's
 * exact field names and status instead of inheriting whatever the serializer is
 * configured to do this week.
 *
 * @implements ProcessorInterface<AuthTokenRequest, Response>
 */
final class AuthTokenProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ApiTokenManager $tokens,
        private readonly RequestStack $requestStack,
        private readonly RateLimiterFactoryInterface $apiTokenIdLimiter,
        private readonly RateLimiterFactoryInterface $apiTokenIpLimiter,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $this->assertNotThrottled($data->rangerId);

        $user = $this->users->findOneByFieldIdentifier($data->rangerId);

        /*
         * One answer for "no such ranger" and "wrong passcode", and the hash is
         * verified even when the user is missing. Both matter: differing replies
         * turn this endpoint into a directory of who works here, and returning
         * early on an unknown id makes the timing say the same thing out loud.
         */
        $valid = $user instanceof User
            && $this->passwordHasher->isPasswordValid($user, $data->passcode);

        if (!$valid) {
            throw new ApiProblemException(Response::HTTP_UNAUTHORIZED, 'invalid_credentials', 'That service number and passcode do not match an account.', /* §10: 401 is never retried in a loop — the ranger must act. */ retryable: false);
        }

        [$plaintext, $token] = $this->tokens->issue(
            $user,
            $data->deviceId ?? $this->deviceHeader(),
            $data->deviceName,
        );

        return new JsonResponse([
            'token' => $plaintext,
            'expiresAt' => ContractFormat::timestamp($token->getExpiresAt()),
            'ranger' => [
                'id' => ContractFormat::rangerId($user),
                'name' => $user->getFullName(),
                // What the app's account screen prints under the name: the held
                // position's name, or the tier's label for the unfiled — never blank.
                'role' => $user->getPosition()?->getName() ?? $user->getTeamRole()->label(),
            ],
        ]);
    }

    /**
     * The API twin of the web form's login_throttling: five tries per identifier
     * and twenty per IP each minute. Counted BEFORE verification so failures and
     * successes both consume — a valid credential replayed in a storm is throttled
     * like any other guess. 429 is retryable by the contract's rules (§10).
     */
    private function assertNotThrottled(string $rangerId): void
    {
        $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';

        if (!$this->apiTokenIdLimiter->create(strtolower($rangerId))->consume()->isAccepted()
            || !$this->apiTokenIpLimiter->create($ip)->consume()->isAccepted()) {
            throw new ApiProblemException(Response::HTTP_TOO_MANY_REQUESTS, 'too_many_attempts', 'Too many sign-in attempts — wait a minute and try again.', retryable: true);
        }
    }

    /**
     * `X-Doria-Device` is sent on every request (§1), so accept it as the
     * device id when the body omits one — the app should not have to say the
     * same thing twice for token scoping to work.
     */
    private function deviceHeader(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        if (!$request instanceof Request) {
            return null;
        }

        $header = $request->headers->get('X-Doria-Device');

        return null === $header || '' === trim($header) ? null : trim($header);
    }
}
