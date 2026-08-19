<?php

declare(strict_types=1);

namespace Uhifadhi\Tests\Panther;

use Symfony\Component\Panther\PantherTestCase;
use Symfony\Component\Process\Process;
use Zenstruck\Foundry\Test\Factories;

/**
 * Base for the browser-driven E2E suite. The Panther web server is a separate
 * process, so fixtures must actually COMMIT — every concrete test class carries
 * #[SkipDatabaseRollback] to opt out of DAMA, and the suite runs against its own
 * database (dnca_test_panther, via the TEST_TOKEN dbname suffix) which is dropped
 * and remigrated before EVERY class, so committed rows never leak between classes
 * or into the default suite.
 */
abstract class E2ETestCase extends PantherTestCase
{
    use Factories;

    public static function setUpBeforeClass(): void
    {
        // Route this process AND the Panther web server at the dedicated database.
        $_SERVER['TEST_TOKEN'] = $_ENV['TEST_TOKEN'] = '_panther';
        putenv('TEST_TOKEN=_panther');

        self::ensurePantherDatabase();

        foreach ([
            ['bin/console', 'doctrine:schema:drop', '--force', '--full-database', '--env=test'],
            ['bin/console', 'doctrine:migrations:migrate', '-n', '--env=test'],
        ] as $command) {
            (new Process(['php', ...$command], \dirname(__DIR__, 2), ['TEST_TOKEN' => '_panther']))->mustRun();
        }

        parent::setUpBeforeClass();
    }

    /**
     * Doctrine's database:create would suffix the maintenance connection too, so
     * create the E2E database directly through the base database from the DSN
     * (fundi's cluster uses trust auth — an empty password is fine).
     */
    private static function ensurePantherDatabase(): void
    {
        $databaseUrl = $_SERVER['DATABASE_URL'] ?? '';
        $url = parse_url(\is_string($databaseUrl) ? $databaseUrl : '');
        if (!\is_array($url)) {
            throw new \RuntimeException('Unparseable DATABASE_URL for the E2E suite.');
        }
        $base = ltrim($url['path'] ?? 'dnca', '/');
        $pdo = new \PDO(
            \sprintf('pgsql:host=%s;port=%d;dbname=%s', $url['host'] ?? '127.0.0.1', $url['port'] ?? 5432, $base),
            $url['user'] ?? 'fundi',
            urldecode($url['pass'] ?? ''),
        );
        $target = $base.'_test_panther';
        $statement = $pdo->query(\sprintf("SELECT 1 FROM pg_database WHERE datname = '%s'", $target));
        if (false === ($statement !== false ? $statement->fetchColumn() : false)) {
            $pdo->exec(\sprintf('CREATE DATABASE "%s"', $target));
        }
    }
}
