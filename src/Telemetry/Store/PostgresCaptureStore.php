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

/**
 * The production sink: the SEPARATE `uhifadhi_telemetry` database, reached over the
 * second DBAL connection ("telemetry") that lives in the same PostGIS accessory as
 * the app's own database but shares none of its schema or migration history.
 *
 * It does NOT create its own table. In production the schema is a migration run at
 * deploy time (migrations-telemetry/, applied with `--conn=telemetry`), so that the
 * telemetry schema is versioned and owned exactly like any other — just on its own
 * connection, never tangled with the domain's migrations.
 */
final class PostgresCaptureStore extends AbstractDbalCaptureStore
{
    /**
     * A no-op by design: the `telemetry_capture` table is created and evolved by
     * the telemetry migration, not lazily by the writer. See the class docblock.
     */
    protected function ensureSchema(): void
    {
    }
}
