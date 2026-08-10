<?php

declare(strict_types=1);

namespace App\Foundation;

/**
 * Marker for the Foundation bounded context — cross-cutting entity concerns that
 * every context may use and that depends on nothing (the "seen by all, sees none"
 * shape shared with the planned Platform context).
 *
 * Holds framework-agnostic building blocks, not domain logic:
 *   - Entity/Trait/  UuidTrait (public, URL-safe UUIDv7 addressing) and
 *                    TimestampableTrait (created/updated stamps).
 *
 * Structural marker only.
 */
final class Foundation
{
}
