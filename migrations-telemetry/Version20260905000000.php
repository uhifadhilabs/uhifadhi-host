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

namespace Uhifadhi\Telemetry\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The Telemetry database's first and only table so far: telemetry_capture, the
 * one row per captured /api round-trip that {@see \Uhifadhi\Telemetry\Store\PostgresCaptureStore}
 * writes and the admin screen reads.
 *
 * This runs against the "telemetry" connection ONLY, via the dedicated
 * {@see \Uhifadhi\Telemetry\Command\TelemetryMigrateCommand} (`telemetry:migrate`),
 * so it never touches — and is never touched by — the app's own migration history.
 *
 * Column shapes mirror the SQLite sink exactly (JSON columns as TEXT we encode
 * ourselves; the two "truncated" flags as SMALLINT 0/1 rather than BOOLEAN) so a
 * capture hydrates into the same value object whichever engine stored it.
 */
final class Version20260905000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create telemetry_capture — the API monitor\'s one table, on the telemetry connection.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE telemetry_capture (
            id VARCHAR(36) NOT NULL,
            captured_at VARCHAR(40) NOT NULL,
            method VARCHAR(10) NOT NULL,
            path VARCHAR(2048) NOT NULL,
            query TEXT NOT NULL,
            request_headers TEXT NOT NULL,
            request_body TEXT NOT NULL,
            request_body_truncated SMALLINT NOT NULL DEFAULT 0,
            files TEXT NOT NULL,
            status_code INT NOT NULL,
            response_body TEXT NOT NULL,
            response_body_truncated SMALLINT NOT NULL DEFAULT 0,
            duration_ms INT NOT NULL,
            user_id INT DEFAULT NULL,
            user_email VARCHAR(180) DEFAULT NULL,
            user_agent VARCHAR(512) DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_telemetry_capture_at ON telemetry_capture (captured_at)');
        $this->addSql('CREATE INDEX idx_telemetry_capture_status ON telemetry_capture (status_code)');
        $this->addSql('CREATE INDEX idx_telemetry_capture_email ON telemetry_capture (user_email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE telemetry_capture');
    }
}
