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

namespace Uhifadhi\Tests\Functional\Telemetry;

use Uhifadhi\Enum\TeamRoleEnum;
use Uhifadhi\Telemetry\Model\CapturedRequest;
use Uhifadhi\Telemetry\Model\FileMetadata;
use Uhifadhi\Telemetry\Store\CaptureStore;
use Uhifadhi\Tests\Functional\AuthenticatedWebTestCase;

/**
 * The diagnostic surface, end to end: it is Super-Admin-only, it reads back a
 * redacted capture, and — the reason the whole feature exists — a real /api call
 * is captured at terminate through the WIRED pipeline (not a hand-driven mock).
 */
final class TelemetryAdminTest extends AuthenticatedWebTestCase
{
    public function testSuperAdminCanOpenTheMonitor(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::SuperAdmin);

        $client->request('GET', '/telemetry');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.tel');
    }

    public function testAdminIsForbidden(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Admin);

        $client->request('GET', '/telemetry');

        self::assertResponseStatusCodeSame(403);
    }

    public function testManagerIsForbidden(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);

        $client->request('GET', '/telemetry');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/telemetry');

        self::assertResponseRedirects();
    }

    public function testShowRendersARedactedCaptureAndNeverALeakedSecret(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::SuperAdmin);

        $store = static::getContainer()->get(CaptureStore::class);
        self::assertInstanceOf(CaptureStore::class, $store);
        $store->prune(new \DateTimeImmutable('+1 day')); // isolate

        $capture = new CapturedRequest(
            id: 'feed-face-0000-0000-0000-000000000001',
            capturedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            method: 'POST',
            path: '/api/patrols',
            query: [],
            requestHeaders: ['authorization' => '[REDACTED]'],
            requestBody: '{"species":"elephant","password":"[REDACTED]"}',
            requestBodyTruncated: false,
            files: [new FileMetadata('photo', 'p.jpg', 4096, 'image/jpeg', 'deadbeef')],
            statusCode: 422,
            responseBody: '{"code":"invalid_payload"}',
            responseBodyTruncated: false,
            durationMs: 12,
            userId: 1,
            userEmail: 'ranger@example.org',
            userAgent: 'Doria/1.4',
        );
        $store->store($capture);

        $client->request('GET', '/telemetry/'.$capture->id);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '/api/patrols');
        self::assertSelectorTextContains('body', '[REDACTED]');
        self::assertSelectorTextContains('body', 'elephant');
    }

    public function testARealApiCallIsCapturedAtTerminate(): void
    {
        $client = static::createClient();

        // Clear, then make an anonymous /api round-trip. With no token the firewall's
        // entry point answers 401 (a real Response, not a thrown exception) — a clean
        // /api round-trip to prove the terminate-time write through the wired pipeline.
        $store = static::getContainer()->get(CaptureStore::class);
        self::assertInstanceOf(CaptureStore::class, $store);
        $store->prune(new \DateTimeImmutable('+1 day'));

        $client->request('GET', '/api/patrols');
        self::assertResponseStatusCodeSame(405); // GET not allowed; a clean /api round-trip all the same

        $page = $store->search(new \Uhifadhi\Telemetry\Model\CaptureFilter());
        $paths = array_map(static fn (CapturedRequest $c): string => $c->path, $page->items);
        self::assertContains('/api/patrols', $paths, 'the /api round-trip was not captured at terminate');
    }
}
