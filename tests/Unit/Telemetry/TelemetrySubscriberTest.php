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

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Uhifadhi\Telemetry\Capture\CapturedUserResolver;
use Uhifadhi\Telemetry\Capture\Redactor;
use Uhifadhi\Telemetry\EventSubscriber\TelemetrySubscriber;
use Uhifadhi\Telemetry\Model\CapturedRequest;
use Uhifadhi\Telemetry\Model\CaptureFilter;
use Uhifadhi\Telemetry\Model\CapturePage;
use Uhifadhi\Telemetry\Store\CaptureStore;

/**
 * The subscriber's two jobs: capture every /api round-trip (redacted) at
 * kernel.terminate, and — the rule the whole feature is built around — NEVER let
 * a telemetry failure reach the request it is monitoring.
 */
final class TelemetrySubscriberTest extends TestCase
{
    public function testCapturesAnApiRoundTripAtTerminateWithHeaderAndBodyRedacted(): void
    {
        $store = new CollectingStore();
        $subscriber = $this->subscriber($store, [7, 'ranger@example.org']);

        $request = Request::create(
            '/api/patrols?sync=1',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer super-secret', 'HTTP_USER_AGENT' => 'Doria/1.4'],
            '{"password":"hunter2","species":"elephant"}',
        );
        $response = new Response('{"code":"invalid_payload"}', 422, ['Content-Type' => 'application/json']);

        $this->dispatch($subscriber, $request, $response);

        self::assertCount(1, $store->stored);
        $capture = $store->stored[0];
        self::assertSame('POST', $capture->method);
        self::assertSame('/api/patrols', $capture->path);
        self::assertSame(['sync' => '1'], $capture->query);
        self::assertSame(422, $capture->statusCode);
        self::assertSame(7, $capture->userId);
        self::assertSame('ranger@example.org', $capture->userEmail);
        self::assertSame('Doria/1.4', $capture->userAgent);
        self::assertGreaterThanOrEqual(0, $capture->durationMs);

        // The Authorization header and the password never reach the store.
        self::assertSame(Redactor::REDACTED, $capture->requestHeaders['authorization']);
        self::assertStringNotContainsString('super-secret', json_encode($capture->requestHeaders) ?: '');
        self::assertStringNotContainsString('hunter2', $capture->requestBody);
        self::assertStringContainsString('elephant', $capture->requestBody);
    }

    public function testRedactsTokensInTheResponseBodyToo(): void
    {
        $store = new CollectingStore();
        $subscriber = $this->subscriber($store);

        $request = Request::create('/api/auth/token', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"code":"otp"}');
        $response = new Response('{"token":"AT-do-not-store-me"}', 200, ['Content-Type' => 'application/json']);

        $this->dispatch($subscriber, $request, $response);

        self::assertStringNotContainsString('AT-do-not-store-me', $store->stored[0]->responseBody);
    }

    public function testIgnoresNonApiTraffic(): void
    {
        $store = new CollectingStore();
        $subscriber = $this->subscriber($store);

        $request = Request::create('/dashboard', 'GET');
        $response = new Response('<html>', 200);

        $this->dispatch($subscriber, $request, $response);

        self::assertCount(0, $store->stored);
    }

    public function testAThrowingStoreNeverBreaksTheMonitoredRequest(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())->method('error');

        $subscriber = new TelemetrySubscriber(
            new ThrowingStore(),
            new Redactor(),
            $this->resolver([null, null]),
            $logger,
        );

        $request = Request::create('/api/patrols', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"ok":1}');
        $response = new Response('{}', 500);

        // The whole point: this must complete without a thrown exception.
        $this->dispatch($subscriber, $request, $response);
        $this->addToAssertionCount(1);
    }

    public function testMultipartUploadIsStoredAsMetadataOnlyNeverBytes(): void
    {
        $store = new CollectingStore();
        $subscriber = $this->subscriber($store);

        $tmp = tempnam(sys_get_temp_dir(), 'tel');
        self::assertIsString($tmp);
        file_put_contents($tmp, random_bytes(2048));
        $upload = new \Symfony\Component\HttpFoundation\File\UploadedFile($tmp, 'patrol.jpg', 'image/jpeg', null, true);

        $request = Request::create('/api/patrols', 'POST', ['species' => 'lion'], [], ['photo' => $upload], ['CONTENT_TYPE' => 'multipart/form-data']);
        $response = new Response('{}', 201);

        $this->dispatch($subscriber, $request, $response);

        $capture = $store->stored[0];
        self::assertCount(1, $capture->files);
        self::assertSame('photo', $capture->files[0]->field);
        self::assertSame('patrol.jpg', $capture->files[0]->originalName);
        self::assertSame(2048, $capture->files[0]->size);
        self::assertSame(64, \strlen((string) $capture->files[0]->sha256)); // a real digest
        @unlink($tmp);
    }

    // ------------------------------------------------------------------ helpers

    /** @param array{0: ?int, 1: ?string} $user */
    private function subscriber(CaptureStore $store, array $user = [null, null]): TelemetrySubscriber
    {
        return new TelemetrySubscriber($store, new Redactor(), $this->resolver($user), $this->createStub(LoggerInterface::class));
    }

    /** @param array{0: ?int, 1: ?string} $user */
    private function resolver(array $user): CapturedUserResolver
    {
        return new class($user) implements CapturedUserResolver {
            /** @param array{0: ?int, 1: ?string} $user */
            public function __construct(private array $user)
            {
            }

            public function resolve(): array
            {
                return $this->user;
            }
        };
    }

    private function dispatch(TelemetrySubscriber $subscriber, Request $request, Response $response): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
        $subscriber->onTerminate(new TerminateEvent($kernel, $request, $response));
    }
}

/** Records what it was asked to store; the read side is unused here. */
final class CollectingStore implements CaptureStore
{
    /** @var list<CapturedRequest> */
    public array $stored = [];

    public function store(CapturedRequest $capture): void
    {
        $this->stored[] = $capture;
    }

    public function find(string $id): ?CapturedRequest
    {
        return null;
    }

    public function search(CaptureFilter $filter): CapturePage
    {
        return new CapturePage([], 0, $filter->limit, $filter->offset);
    }

    public function prune(\DateTimeImmutable $before): int
    {
        return 0;
    }
}

final class ThrowingStore implements CaptureStore
{
    public function store(CapturedRequest $capture): void
    {
        throw new \RuntimeException('telemetry DB is down');
    }

    public function find(string $id): ?CapturedRequest
    {
        throw new \RuntimeException('down');
    }

    public function search(CaptureFilter $filter): CapturePage
    {
        throw new \RuntimeException('down');
    }

    public function prune(\DateTimeImmutable $before): int
    {
        throw new \RuntimeException('down');
    }
}
