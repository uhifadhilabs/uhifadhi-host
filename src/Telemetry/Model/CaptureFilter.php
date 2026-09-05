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
 * The admin screen's query, as a value object: who, what status, which endpoint,
 * over what window, and how much of it. Every field is optional — an empty filter
 * is "the most recent captures, failures first", which is the screen the ranger's
 * bug sends you to.
 */
final readonly class CaptureFilter
{
    public function __construct(
        public ?string $userEmail = null,
        public ?int $status = null,
        public bool $failuresOnly = false,
        public ?string $endpoint = null,
        public ?\DateTimeImmutable $since = null,
        public ?\DateTimeImmutable $until = null,
        public int $limit = 50,
        public int $offset = 0,
    ) {
    }
}
