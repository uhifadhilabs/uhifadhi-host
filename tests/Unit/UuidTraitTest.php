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

namespace Uhifadhi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;
use Uhifadhi\Entity\Trait\UuidTrait;

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
