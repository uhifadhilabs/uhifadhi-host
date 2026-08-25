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

namespace Uhifadhi\Api;

use Uhifadhi\Entity\User;

/**
 * The two conversions every /api response depends on, in one place so they
 * cannot drift between endpoints — API-CONTRACT.md §1.
 */
final class ContractFormat
{
    /** ISO-8601, UTC, with a literal Z — never an offset, never a local time. */
    public static function timestamp(\DateTimeInterface $moment): string
    {
        return \DateTimeImmutable::createFromInterface($moment)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Parse a client timestamp. The phone may have been offline for hours, so
     * its `recordedAt`/`loggedAt` are TRUSTED and never replaced with our
     * receive time (§1) — this only normalises the zone.
     */
    public static function parseTimestamp(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value)->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * How a person is identified to the field app, everywhere: the service
     * number they know themselves by, falling back to the email address for
     * staff who have none. Whatever this returns in `/api/areas/mine`'s roster
     * is exactly what comes back in a patrol's `team`, so there is one
     * implementation and every caller uses it.
     */
    public static function rangerId(User $user): string
    {
        return $user->getRangerCode() ?? (string) $user->getEmail();
    }
}
