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
 * One page of search results plus the total that matched, so the admin screen can
 * page without a second round-trip to count.
 */
final readonly class CapturePage
{
    /** @param list<CapturedRequest> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $limit,
        public int $offset,
    ) {
    }

    public function hasNext(): bool
    {
        return $this->offset + $this->limit < $this->total;
    }

    public function hasPrevious(): bool
    {
        return $this->offset > 0;
    }
}
