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

namespace Uhifadhi\Telemetry\Model;

/**
 * A body after redaction: the safe-to-store text, and whether the size cap cut
 * it short. Truncation is carried as its own flag rather than inferred from a
 * trailing marker, so the admin screen can say "shown up to the cap" honestly.
 */
final readonly class RedactedBody
{
    public function __construct(
        public string $body,
        public bool $truncated,
    ) {
    }
}
