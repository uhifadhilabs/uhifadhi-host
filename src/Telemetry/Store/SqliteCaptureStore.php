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
 * The dev/test sink: a single file (or in-memory) SQLite database, no separate
 * server, no migration step. It owns its schema and creates it on first touch,
 * because the whole appeal here is that a developer clones the repo and the
 * monitor just works — there is no telemetry Postgres to provision locally.
 *
 * In production this class is never selected (see config/packages/telemetry.yaml):
 * SQLite's single-writer model is wrong for a truck syncing hundreds of requests
 * at once, which is exactly what {@see PostgresCaptureStore} is for.
 */
final class SqliteCaptureStore extends AbstractDbalCaptureStore
{
    private bool $schemaReady = false;

    protected function ensureSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }

        $this->connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS '.self::TABLE.' (
                id TEXT PRIMARY KEY,
                captured_at TEXT NOT NULL,
                method TEXT NOT NULL,
                path TEXT NOT NULL,
                query TEXT NOT NULL,
                request_headers TEXT NOT NULL,
                request_body TEXT NOT NULL,
                request_body_truncated INTEGER NOT NULL DEFAULT 0,
                files TEXT NOT NULL,
                status_code INTEGER NOT NULL,
                response_body TEXT NOT NULL,
                response_body_truncated INTEGER NOT NULL DEFAULT 0,
                duration_ms INTEGER NOT NULL,
                user_id INTEGER NULL,
                user_email TEXT NULL,
                user_agent TEXT NULL
            )',
        );
        $this->connection->executeStatement(
            'CREATE INDEX IF NOT EXISTS idx_'.self::TABLE.'_at ON '.self::TABLE.' (captured_at)',
        );

        $this->schemaReady = true;
    }
}
