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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Uhifadhi\Api\ApiProblemException;
use Uhifadhi\Api\ContractFormat;
use Uhifadhi\ApiResource\Me;
use Uhifadhi\Entity\User;
use Uhifadhi\Service\PermissionCatalogueService;

/**
 * Answers `GET /api/me` — API-CONTRACT.md §2A.
 *
 * @implements ProviderInterface<Me>
 */
final class MeProvider implements ProviderInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly PermissionCatalogueService $catalogue,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Me
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof User) {
            // 401, not 403: the caller has not proved who they are. A 404 would also be safe by
            // §2A's second rule, but it would tell a signed-out app "this host has no permissions
            // endpoint" — which is a different and wrong thing to believe.
            throw new ApiProblemException(Response::HTTP_UNAUTHORIZED, 'unauthorized', 'Sign in again.', retryable: false);
        }

        return new Me(self::ranger($user), $this->catalogue->heldBy($user));
    }

    /**
     * The account as the app prints it. `role` is load-bearing rather than cosmetic (§2A): the
     * screens shown to a refused ranger name the POSITION that lacks the permission, because that
     * is what an administrator has to change.
     *
     * @return array{id: string, name: string, role: string}
     */
    public static function ranger(User $user): array
    {
        return [
            'id' => ContractFormat::rangerId($user),
            'name' => $user->getFullName(),
            'role' => $user->getPosition()?->getName() ?? $user->getTeamRole()->label(),
        ];
    }
}
