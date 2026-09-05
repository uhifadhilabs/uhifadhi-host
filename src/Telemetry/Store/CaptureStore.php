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

namespace Uhifadhi\Telemetry\Store;

use Uhifadhi\Telemetry\Model\CapturedRequest;
use Uhifadhi\Telemetry\Model\CaptureFilter;
use Uhifadhi\Telemetry\Model\CapturePage;

/**
 * The sink port — the one seam the rest of Telemetry depends on.
 *
 * The subscriber writes through {@see self::store()} and never learns where the
 * bytes land; the admin screen reads through {@see self::search()} / {@see self::find()}
 * and never learns either. Today two adapters implement it (Postgres for prod, a
 * file-backed SQLite for dev/test); moving captures to TimescaleDB, a log backend
 * or an async Messenger transport later is a new class behind this interface, not
 * a change to anything that calls it.
 */
interface CaptureStore
{
    /** Persist one captured round-trip. Called at kernel.terminate, off the request's critical path. */
    public function store(CapturedRequest $capture): void;

    public function find(string $id): ?CapturedRequest;

    public function search(CaptureFilter $filter): CapturePage;

    /**
     * Drop everything captured strictly before the given instant.
     *
     * @return int rows removed
     */
    public function prune(\DateTimeImmutable $before): int;
}
