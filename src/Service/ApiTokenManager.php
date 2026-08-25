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

namespace Uhifadhi\Service;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Entity\ApiToken;
use Uhifadhi\Entity\User;
use Uhifadhi\Repository\ApiTokenRepository;

/**
 * Mints and checks the field app's bearer tokens — the only place a token
 * string exists in plaintext.
 *
 * The contract asks for a token measured in months and offers no refresh call,
 * because a ranger cannot re-authenticate from the bush and an expiry mid-patrol
 * must never cost recorded work. That is why the token is a database row rather
 * than a signed blob: months of validity is only safe if it can be withdrawn.
 */
final class ApiTokenManager
{
    /**
     * Six months. Long enough to cover a posting; short enough that a handset
     * that quietly left service stops working within one.
     */
    public const string LIFETIME = 'P180D';

    /** 32 bytes of CSPRNG output, hex — 256 bits, well past guessing. */
    private const int TOKEN_BYTES = 32;

    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Issue a token for one device, returning the plaintext ONCE — it is not
     * recoverable afterwards, by us or by anyone reading the database.
     *
     * Re-signing in on a handset the user already has a token for ROTATES that
     * row instead of adding another, so "sign in again" after a wipe cannot
     * leave a trail of live credentials behind it.
     *
     * @return array{0: string, 1: ApiToken} [plaintext token, its record]
     */
    public function issue(User $user, ?string $deviceId = null, ?string $deviceName = null): array
    {
        $plaintext = bin2hex(random_bytes(self::TOKEN_BYTES));
        $hash = self::hash($plaintext);
        $expiresAt = new \DateTimeImmutable()->add(new \DateInterval(self::LIFETIME));

        $token = null !== $deviceId ? $this->tokens->findOneForDevice($user, $deviceId) : null;

        if ($token instanceof ApiToken) {
            $token->reissue($hash, $expiresAt)->setDeviceName($deviceName);
        } else {
            $token = new ApiToken($user, $hash, $expiresAt)
                ->setDeviceId($deviceId)
                ->setDeviceName($deviceName);
            $this->entityManager->persist($token);
        }

        $this->entityManager->flush();

        return [$plaintext, $token];
    }

    /**
     * The record behind a presented token, or null when it is unknown, revoked
     * or expired. All three answer the same way on purpose: telling a caller
     * *which* of the three it was tells an attacker whether a guess existed.
     */
    public function find(string $plaintext, ?\DateTimeImmutable $now = null): ?ApiToken
    {
        $token = $this->tokens->findOneByHash(self::hash($plaintext));

        return $token?->isUsableAt($now ?? new \DateTimeImmutable()) ? $token : null;
    }

    /**
     * Record that a token was seen, at most once a day. Every request writing a
     * timestamp would make each authenticated READ a database WRITE — a real
     * cost on a sync burst of hundreds of track batches — for a field nobody
     * reads to the minute.
     */
    public function touch(ApiToken $token, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        $last = $token->getLastUsedAt();

        if (null !== $last && $last > $now->sub(new \DateInterval('P1D'))) {
            return;
        }

        $token->setLastUsedAt($now);
        $this->entityManager->flush();
    }

    /**
     * SHA-256, not a password hash: this is a 256-bit random string, not a
     * guessable secret, so there is nothing for bcrypt's work factor to defend
     * against — and a per-request bcrypt verify would be a self-inflicted
     * denial of service on a sync burst. Unsalted is required, not an oversight:
     * the hash IS the lookup key.
     */
    private static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
