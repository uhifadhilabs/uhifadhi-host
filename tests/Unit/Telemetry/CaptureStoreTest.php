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

namespace Uhifadhi\Tests\Unit\Telemetry;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Telemetry\Model\CapturedRequest;
use Uhifadhi\Telemetry\Model\CaptureFilter;
use Uhifadhi\Telemetry\Model\FileMetadata;
use Uhifadhi\Telemetry\Store\AbstractDbalCaptureStore;
use Uhifadhi\Telemetry\Store\CaptureStore;
use Uhifadhi\Telemetry\Store\PostgresCaptureStore;
use Uhifadhi\Telemetry\Store\SqliteCaptureStore;

/**
 * Both adapters, one contract. Every case runs against BOTH the SQLite sink and the
 * Postgres sink so the interface really is a swap point and not a fiction: the
 * Postgres adapter's schema-less nature is honoured by pre-creating the table (its
 * migration's job in production), and everything else runs through the shared,
 * portable query surface.
 */
final class CaptureStoreTest extends TestCase
{
    /** @return iterable<string, array{callable(): CaptureStore}> */
    public static function stores(): iterable
    {
        yield 'sqlite (dev/test sink)' => [static fn (): CaptureStore => new SqliteCaptureStore(self::memory())];
        yield 'postgres (prod sink)' => [static function (): CaptureStore {
            $conn = self::memory();
            self::createTable($conn); // stands in for the telemetry migration

            return new PostgresCaptureStore($conn);
        }];
    }

    /** @param callable(): CaptureStore $make */
    #[DataProvider('stores')]
    public function testStoreAndFindRoundTripsEveryField(callable $make): void
    {
        $store = $make();
        $capture = self::capture(id: 'c1', status: 422, path: '/api/patrols', email: 'ranger@example.org');
        $store->store($capture);

        $found = $store->find('c1');

        self::assertInstanceOf(CapturedRequest::class, $found);
        self::assertSame('c1', $found->id);
        self::assertSame(422, $found->statusCode);
        self::assertSame('/api/patrols', $found->path);
        self::assertSame('ranger@example.org', $found->userEmail);
        self::assertSame(['sync' => '1'], $found->query);
        self::assertSame('[REDACTED]', $found->requestHeaders['authorization']);
        self::assertCount(1, $found->files);
        self::assertSame('photo', $found->files[0]->field);
        self::assertSame('abc123', $found->files[0]->sha256);
        self::assertTrue($found->requestBodyTruncated);
    }

    /** @param callable(): CaptureStore $make */
    #[DataProvider('stores')]
    public function testFindReturnsNullForUnknownId(callable $make): void
    {
        self::assertNull($make()->find('nope'));
    }

    /** @param callable(): CaptureStore $make */
    #[DataProvider('stores')]
    public function testSearchOrdersFailuresFirstThenNewest(callable $make): void
    {
        $store = $make();
        $store->store(self::capture(id: 'ok-old', status: 200, at: '2026-09-01T10:00:00+00:00'));
        $store->store(self::capture(id: 'ok-new', status: 200, at: '2026-09-04T10:00:00+00:00'));
        $store->store(self::capture(id: 'fail-old', status: 500, at: '2026-09-02T10:00:00+00:00'));
        $store->store(self::capture(id: 'fail-new', status: 422, at: '2026-09-03T10:00:00+00:00'));

        $page = $store->search(new CaptureFilter());

        self::assertSame(4, $page->total);
        $ids = array_map(static fn (CapturedRequest $c): string => $c->id, $page->items);
        // Failures first (newest failure before older failure), then 200s (newest first).
        self::assertSame(['fail-new', 'fail-old', 'ok-new', 'ok-old'], $ids);
    }

    /** @param callable(): CaptureStore $make */
    #[DataProvider('stores')]
    public function testSearchFiltersByUserStatusEndpointFailuresAndWindow(callable $make): void
    {
        $store = $make();
        $store->store(self::capture(id: 'a', status: 200, path: '/api/patrols', email: 'a@x.org', at: '2026-09-01T00:00:00+00:00'));
        $store->store(self::capture(id: 'b', status: 422, path: '/api/patrols', email: 'b@x.org', at: '2026-09-02T00:00:00+00:00'));
        $store->store(self::capture(id: 'c', status: 500, path: '/api/incidents', email: 'a@x.org', at: '2026-09-03T00:00:00+00:00'));

        // a@x.org owns a(200) and c(500); failures-first puts the 500 ahead of the 200.
        self::assertSame(['c', 'a'], self::ids($store->search(new CaptureFilter(userEmail: 'a@x.org'))));
        self::assertSame(['b'], self::ids($store->search(new CaptureFilter(status: 422))));
        self::assertSame(['c', 'b'], self::ids($store->search(new CaptureFilter(failuresOnly: true)))); // both failures; c(09-03) newer than b(09-02)
        self::assertSame(['b', 'a'], self::ids($store->search(new CaptureFilter(endpoint: 'patrols'))));
        self::assertSame(
            ['c', 'b'],
            self::ids($store->search(new CaptureFilter(since: new \DateTimeImmutable('2026-09-02T00:00:00+00:00')))),
        );
    }

    /** @param callable(): CaptureStore $make */
    #[DataProvider('stores')]
    public function testSearchPaginatesWithTotal(callable $make): void
    {
        $store = $make();
        for ($i = 0; $i < 5; ++$i) {
            $store->store(self::capture(id: 'x'.$i, status: 200, at: \sprintf('2026-09-0%dT00:00:00+00:00', $i + 1)));
        }

        $page = $store->search(new CaptureFilter(limit: 2, offset: 0));

        self::assertSame(5, $page->total);
        self::assertCount(2, $page->items);
        self::assertTrue($page->hasNext());
        self::assertFalse($page->hasPrevious());
    }

    /** @param callable(): CaptureStore $make */
    #[DataProvider('stores')]
    public function testPruneDropsCapturesBeforeTheCutoffOnly(callable $make): void
    {
        $store = $make();
        $store->store(self::capture(id: 'old', status: 200, at: '2026-08-01T00:00:00+00:00'));
        $store->store(self::capture(id: 'keep', status: 200, at: '2026-09-01T00:00:00+00:00'));

        $removed = $store->prune(new \DateTimeImmutable('2026-08-15T00:00:00+00:00'));

        self::assertSame(1, $removed);
        self::assertNull($store->find('old'));
        self::assertNotNull($store->find('keep'));
    }

    // ------------------------------------------------------------------ helpers

    private static function memory(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    private static function createTable(Connection $conn): void
    {
        // Postgres' schema is normally a migration; in this SQLite-backed test we
        // stand it up with portable DDL so the Postgres adapter has a table to use.
        $ref = new \ReflectionClass(AbstractDbalCaptureStore::class);
        $table = $ref->getConstant('TABLE');
        \assert(\is_string($table));
        $conn->executeStatement(
            'CREATE TABLE '.$table.' (
                id TEXT PRIMARY KEY, captured_at TEXT NOT NULL, method TEXT NOT NULL, path TEXT NOT NULL,
                query TEXT NOT NULL, request_headers TEXT NOT NULL, request_body TEXT NOT NULL,
                request_body_truncated INTEGER NOT NULL, files TEXT NOT NULL, status_code INTEGER NOT NULL,
                response_body TEXT NOT NULL, response_body_truncated INTEGER NOT NULL, duration_ms INTEGER NOT NULL,
                user_id INTEGER NULL, user_email TEXT NULL, user_agent TEXT NULL
            )',
        );
    }

    private static function capture(
        string $id,
        int $status,
        string $path = '/api/patrols',
        ?string $email = 'ranger@example.org',
        string $at = '2026-09-05T12:00:00+00:00',
    ): CapturedRequest {
        return new CapturedRequest(
            id: $id,
            capturedAt: new \DateTimeImmutable($at),
            method: 'POST',
            path: $path,
            query: ['sync' => '1'],
            requestHeaders: ['authorization' => '[REDACTED]', 'content-type' => 'application/json'],
            requestBody: '{"species":"elephant"}',
            requestBodyTruncated: true,
            files: [new FileMetadata('photo', 'p.jpg', 2048, 'image/jpeg', 'abc123')],
            statusCode: $status,
            responseBody: '{"code":"invalid_payload"}',
            responseBodyTruncated: false,
            durationMs: 42,
            userId: 7,
            userEmail: $email,
            userAgent: 'Doria/1.4',
        );
    }

    /** @return list<string> */
    private static function ids(\Uhifadhi\Telemetry\Model\CapturePage $page): array
    {
        return array_map(static fn (CapturedRequest $c): string => $c->id, $page->items);
    }
}
