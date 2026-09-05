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
 * One captured API round-trip — the whole record Telemetry stores and the admin
 * screen reads back. Immutable: it is assembled once, at kernel.terminate, from
 * pieces gathered across the request and never edited afterwards.
 *
 * Everything credential-shaped is ALREADY GONE by the time a value of this type
 * exists: the headers and bodies here have passed through {@see \Uhifadhi\Telemetry\Capture\Redactor},
 * and photo bytes have been replaced by digests. This type holds no policy of
 * its own — it is the redacted result, not the redactor.
 */
final readonly class CapturedRequest
{
    /**
     * @param array<string, mixed> $query          the query string, parsed
     * @param array<string, mixed> $requestHeaders redacted request headers
     * @param list<FileMetadata>   $files          uploaded-file metadata (never bytes)
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $capturedAt,
        public string $method,
        public string $path,
        public array $query,
        public array $requestHeaders,
        public string $requestBody,
        public bool $requestBodyTruncated,
        public array $files,
        public int $statusCode,
        public string $responseBody,
        public bool $responseBodyTruncated,
        public int $durationMs,
        public ?int $userId,
        public ?string $userEmail,
        public ?string $userAgent,
    ) {
    }

    /** A failure is anything the client would treat as one: 4xx and 5xx. */
    public function isFailure(): bool
    {
        return $this->statusCode >= 400;
    }

    /** @return array<string, mixed> the row shape the stores read and write */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'captured_at' => $this->capturedAt->format(\DateTimeInterface::ATOM),
            'method' => $this->method,
            'path' => $this->path,
            'query' => self::encode($this->query),
            'request_headers' => self::encode($this->requestHeaders),
            'request_body' => $this->requestBody,
            'request_body_truncated' => $this->requestBodyTruncated ? 1 : 0,
            'files' => self::encode(array_map(static fn (FileMetadata $f): array => $f->toArray(), $this->files)),
            'status_code' => $this->statusCode,
            'response_body' => $this->responseBody,
            'response_body_truncated' => $this->responseBodyTruncated ? 1 : 0,
            'duration_ms' => $this->durationMs,
            'user_id' => $this->userId,
            'user_email' => $this->userEmail,
            'user_agent' => $this->userAgent,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        /** @var list<array<string, mixed>> $files */
        $files = array_values(array_filter(self::decode($row['files'] ?? null), 'is_array'));

        return new self(
            self::str($row['id'] ?? null) ?? '',
            new \DateTimeImmutable(self::str($row['captured_at'] ?? null) ?? 'now'),
            self::str($row['method'] ?? null) ?? '',
            self::str($row['path'] ?? null) ?? '',
            self::decodeMap($row['query'] ?? null),
            self::decodeMap($row['request_headers'] ?? null),
            self::str($row['request_body'] ?? null) ?? '',
            (bool) ($row['request_body_truncated'] ?? false),
            array_map(static fn (array $f): FileMetadata => FileMetadata::fromArray($f), $files),
            is_numeric($row['status_code'] ?? null) ? (int) $row['status_code'] : 0,
            self::str($row['response_body'] ?? null) ?? '',
            (bool) ($row['response_body_truncated'] ?? false),
            is_numeric($row['duration_ms'] ?? null) ? (int) $row['duration_ms'] : 0,
            is_numeric($row['user_id'] ?? null) ? (int) $row['user_id'] : null,
            self::str($row['user_email'] ?? null),
            self::str($row['user_agent'] ?? null),
        );
    }

    private static function str(mixed $value): ?string
    {
        return \is_scalar($value) ? (string) $value : null;
    }

    /** @param array<mixed> $value */
    private static function encode(array $value): string
    {
        return json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '[]';
    }

    /**
     * @return array<mixed>
     */
    private static function decode(mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }
        if (!\is_string($value) || '' === $value) {
            return [];
        }
        $decoded = json_decode($value, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * The same, but for the JSON objects (query, headers) whose keys are strings.
     *
     * @return array<string, mixed>
     */
    private static function decodeMap(mixed $value): array
    {
        $out = [];
        foreach (self::decode($value) as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }
}
