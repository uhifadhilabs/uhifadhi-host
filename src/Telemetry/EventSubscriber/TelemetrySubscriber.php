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

namespace Uhifadhi\Telemetry\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Telemetry\Capture\CapturedUserResolver;
use Uhifadhi\Telemetry\Capture\Redactor;
use Uhifadhi\Telemetry\Model\CapturedRequest;
use Uhifadhi\Telemetry\Model\FileMetadata;
use Uhifadhi\Telemetry\Store\CaptureStore;

/**
 * The capture pipeline for every `^/api` round-trip. Three touch-points, one rule.
 *
 * WHY THREE:
 *  - {@see onRequest} snapshots the RAW request body and the uploaded-file handles
 *    BEFORE any deserializer runs. An "invalid payload" is precisely the case where
 *    the parsed DTO is null, so the parsed DTO is worthless as evidence — the bytes
 *    on the wire are the evidence, and they must be taken before something consumes
 *    the stream or the temp files are moved.
 *  - {@see onResponse} reads the authenticated user while the security token is still
 *    in scope.
 *  - {@see onTerminate} assembles and PERSISTS the record — after the response has
 *    been flushed to the ranger. Not one millisecond of the write is on the request's
 *    critical path.
 *
 * THE ONE RULE: the monitor must never break the thing it monitors. Every touch-point
 * is wrapped so that any failure — a full disk, a telemetry DB that is down, a body
 * that will not decode — is logged and swallowed, never propagated. A broken monitor
 * is a lost capture; a monitor that throws is a lost patrol.
 */
final readonly class TelemetrySubscriber
{
    private const string ATTR_BODY = '_telemetry_raw_body';
    private const string ATTR_FILES = '_telemetry_files';
    private const string ATTR_ACTIVE = '_telemetry_active';

    public function __construct(
        private CaptureStore $store,
        private Redactor $redactor,
        private CapturedUserResolver $userResolver,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * First in, so the body is read before any listener that might consume it.
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4096)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !self::isMonitored($request)) {
            return;
        }

        try {
            $request->attributes->set(self::ATTR_ACTIVE, true);
            // getContent() is re-readable for the JSON/form bodies the API takes,
            // but snapshotting here removes all doubt about what a later layer did.
            $request->attributes->set(self::ATTR_BODY, $request->getContent());
            $request->attributes->set(self::ATTR_FILES, $this->fileMetadata($request));
        } catch (\Throwable $e) {
            $this->swallow('capture request', $e);
        }
    }

    /**
     * Resolve the user while the token is live; stash id/email for terminate.
     */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -4096)]
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->attributes->getBoolean(self::ATTR_ACTIVE)) {
            return;
        }

        try {
            [$id, $email] = $this->userResolver->resolve();
            $request->attributes->set('_telemetry_user_id', $id);
            $request->attributes->set('_telemetry_user_email', $email);
        } catch (\Throwable $e) {
            $this->swallow('resolve user', $e);
        }
    }

    /**
     * Assemble + persist, after the response is gone. This is the only write.
     */
    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->attributes->getBoolean(self::ATTR_ACTIVE)) {
            return;
        }

        try {
            $response = $event->getResponse();

            $storedBody = $request->attributes->get(self::ATTR_BODY);
            $rawBody = \is_string($storedBody) ? $storedBody : '';
            $requestBody = $this->redactor->redactBody($rawBody, $request->headers->get('Content-Type'));

            $rawResponse = $response->getContent();
            $responseBody = $this->redactor->redactBody(
                \is_string($rawResponse) ? $rawResponse : '',
                $response->headers->get('Content-Type'),
            );

            /** @var list<FileMetadata> $files */
            $files = $request->attributes->get(self::ATTR_FILES) ?? [];

            $start = $request->server->get('REQUEST_TIME_FLOAT');
            $durationMs = (int) round((microtime(true) - (is_numeric($start) ? (float) $start : microtime(true))) * 1000);

            $userId = $request->attributes->get('_telemetry_user_id');
            $userEmail = $request->attributes->get('_telemetry_user_email');

            $this->store->store(new CapturedRequest(
                id: Uuid::v7()->toRfc4122(),
                capturedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                method: $request->getMethod(),
                path: $request->getPathInfo(),
                query: $request->query->all(),
                requestHeaders: $this->redactor->redactHeaders($request->headers->all()),
                requestBody: $requestBody->body,
                requestBodyTruncated: $requestBody->truncated,
                files: $files,
                statusCode: $response->getStatusCode(),
                responseBody: $responseBody->body,
                responseBodyTruncated: $responseBody->truncated,
                durationMs: max(0, $durationMs),
                userId: \is_int($userId) ? $userId : null,
                userEmail: \is_string($userEmail) ? $userEmail : null,
                userAgent: $request->headers->get('User-Agent'),
            ));
        } catch (\Throwable $e) {
            $this->swallow('persist capture', $e);
        }
    }

    private static function isMonitored(Request $request): bool
    {
        $path = $request->getPathInfo();

        return '/api' === $path || str_starts_with($path, '/api/');
    }

    /**
     * Uploaded files as metadata ONLY — never their bytes. Walks nested file
     * inputs so a `items[0][photo]` upload is recorded under a readable field name.
     *
     * @return list<FileMetadata>
     */
    private function fileMetadata(Request $request): array
    {
        $out = [];
        foreach (self::flattenFiles($request->files->all()) as $field => $file) {
            try {
                $path = $file->getPathname();
                $out[] = new FileMetadata(
                    field: $field,
                    originalName: $file->getClientOriginalName(),
                    size: (int) (is_file($path) ? filesize($path) : $file->getSize()),
                    mimeType: $file->getClientMimeType(),
                    sha256: is_file($path) ? (hash_file('sha256', $path) ?: null) : null,
                );
            } catch (\Throwable $e) {
                $this->swallow('read upload metadata', $e);
            }
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $files
     *
     * @return array<string, UploadedFile>
     */
    private static function flattenFiles(array $files, string $prefix = ''): array
    {
        $out = [];
        foreach ($files as $key => $value) {
            $name = '' === $prefix ? (string) $key : $prefix.'['.$key.']';
            if ($value instanceof UploadedFile) {
                $out[$name] = $value;
            } elseif (\is_array($value)) {
                $out += self::flattenFiles($value, $name);
            }
        }

        return $out;
    }

    private function swallow(string $stage, \Throwable $e): void
    {
        // Logged, never rethrown — see the class docblock's one rule.
        $this->logger->error('telemetry: {stage} failed', ['stage' => $stage, 'exception' => $e]);
    }
}
