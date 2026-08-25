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

namespace Uhifadhi\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Uhifadhi\Entity\ApiToken;
use Uhifadhi\Entity\User;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
final class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    /**
     * The authenticator's one query: the token row for a presented string,
     * found by its hash. Expiry and revocation are decided in the entity, not
     * here, so an expired token is still FOUND — the caller can then say
     * "expired" rather than "unknown".
     */
    public function findOneByHash(string $tokenHash): ?ApiToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    /** The token already issued to this handset, if any — re-signing in rotates it. */
    public function findOneForDevice(User $owner, string $deviceId): ?ApiToken
    {
        return $this->findOneBy(['owner' => $owner, 'deviceId' => $deviceId]);
    }
}
