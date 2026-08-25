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

namespace Uhifadhi\Entity;

use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\Entity\Trait\TimestampableTrait;
use Uhifadhi\Entity\Trait\UuidTrait;
use Uhifadhi\Repository\ApiTokenRepository;

/**
 * A bearer token issued to ONE device for ONE staff account — the field app's
 * only credential after sign-in.
 *
 * Opaque, not a JWT, and the reason is the field: the contract asks for a
 * months-long token and offers no refresh endpoint, because a ranger cannot
 * re-authenticate from the bush. A months-long *self-contained* token cannot be
 * withdrawn — a lost phone would stay authorised until it expired. This row is
 * the withdrawal: {@see $revokedAt} takes effect on the next request.
 *
 * The token STRING is never stored. Only its SHA-256 hash is, so a database
 * leak yields nothing a phone could present; the plaintext exists once, in the
 * sign-in response. Lookup is by hash, which is why the column is indexed and
 * unique rather than searched.
 */
#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\Table(name: 'api_token')]
#[ORM\HasLifecycleCallbacks]
class ApiToken
{
    use TimestampableTrait;
    use UuidTrait;

    /** SHA-256 of the token string, hex — 64 chars, fixed. */
    public const int HASH_LENGTH = 64;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    /** SHA-256 hex of the presented token — the lookup key, never the token itself. */
    #[ORM\Column(length: self::HASH_LENGTH, unique: true)]
    private string $tokenHash;

    /**
     * The `X-Doria-Device` install UUID this token was minted for. Kept so a
     * single lost handset can be revoked without signing the ranger out of a
     * replacement, and so re-signing in on the same device replaces its token
     * rather than accumulating one per attempt.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $deviceId = null;

    /** Human label from the phone ("Doria on Pixel 7a") — for the revoke screen. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $deviceName = null;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    /** Set when an admin withdraws the token; a revoked token authenticates nobody. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /**
     * Last time this token authenticated a request. Written at most once a day
     * (see ApiTokenAuthenticator) — an every-request UPDATE would turn each
     * read into a write for no operational gain.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct(User $owner, string $tokenHash, \DateTimeImmutable $expiresAt)
    {
        $this->owner = $owner;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->initTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getDeviceId(): ?string
    {
        return $this->deviceId;
    }

    public function setDeviceId(?string $deviceId): static
    {
        $this->deviceId = $deviceId;

        return $this;
    }

    public function getDeviceName(): ?string
    {
        return $this->deviceName;
    }

    public function setDeviceName(?string $deviceName): static
    {
        $this->deviceName = $deviceName;

        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(?\DateTimeImmutable $at = null): static
    {
        $this->revokedAt ??= $at ?? new \DateTimeImmutable();

        return $this;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?\DateTimeImmutable $lastUsedAt): static
    {
        $this->lastUsedAt = $lastUsedAt;

        return $this;
    }

    /** Neither withdrawn nor past its expiry — the only state that authenticates. */
    public function isUsableAt(\DateTimeImmutable $now): bool
    {
        return null === $this->revokedAt && $this->expiresAt > $now;
    }

    /** Rotate this device's token in place rather than minting a second row. */
    public function reissue(string $tokenHash, \DateTimeImmutable $expiresAt): static
    {
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->revokedAt = null;
        $this->lastUsedAt = null;

        return $this;
    }
}
