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

namespace Uhifadhi\Telemetry\Capture;

/**
 * Answers "who was authenticated on this request?" for the capture.
 *
 * A seam rather than a direct reach into the firewall, for two reasons: it keeps
 * the subscriber trivially unit-testable (no security container to stand up), and
 * it keeps the one host-coupled line — how THIS app models a user — in a single
 * class that the eventual telemetry-module can re-point without touching the
 * capture pipeline.
 */
interface CapturedUserResolver
{
    /**
     * @return array{0: ?int, 1: ?string} [userId, userEmail]; both null when anonymous
     */
    public function resolve(): array;
}
