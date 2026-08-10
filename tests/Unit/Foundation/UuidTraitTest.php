<?php

declare(strict_types=1);

namespace App\Tests\Unit\Foundation;

use App\Foundation\Entity\Trait\UuidTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

final class UuidTraitTest extends TestCase
{
    public function testGenerateUuidSetsATimeOrderedV7Once(): void
    {
        $entity = new class {
            use UuidTrait;
        };

        self::assertNull($entity->getUuid());
        self::assertNull($entity->getUuidString());

        $entity->generateUuid();
        $uuid = $entity->getUuid();

        self::assertInstanceOf(UuidV7::class, $uuid, 'external ids are time-ordered UUIDv7');
        self::assertSame($uuid->toRfc4122(), $entity->getUuidString());

        // Idempotent — a second persist must not replace an existing id.
        $entity->generateUuid();
        self::assertSame($uuid, $entity->getUuid());
    }
}
