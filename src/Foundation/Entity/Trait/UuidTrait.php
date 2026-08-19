<?php

declare(strict_types=1);

namespace Uhifadhi\Foundation\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Gives an entity a public, time-ordered UUIDv7 used for external addressing (URLs,
 * APIs) so the sequential integer primary key is never exposed. Generated on first
 * persist; the entity needs #[ORM\HasLifecycleCallbacks].
 */
trait UuidTrait
{
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $uuid = null;

    public function getUuid(): ?Uuid
    {
        return $this->uuid;
    }

    public function setUuid(Uuid $uuid): static
    {
        $this->uuid = $uuid;

        return $this;
    }

    public function getUuidString(): ?string
    {
        return $this->uuid?->toRfc4122();
    }

    #[ORM\PrePersist]
    public function generateUuid(): void
    {
        $this->uuid ??= Uuid::v7();
    }
}
