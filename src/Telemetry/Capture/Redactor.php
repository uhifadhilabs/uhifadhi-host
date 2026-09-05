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

namespace Uhifadhi\Telemetry\Capture;

use Uhifadhi\Telemetry\Model\RedactedBody;

/**
 * The privacy boundary. Everything Telemetry stores passes through here first,
 * and three things must never come out the other side:
 *
 *  1. CREDENTIALS — Authorization/Cookie headers and any password/token/secret
 *     field in a body are replaced with {@see self::REDACTED}. The monitor exists
 *     to be read by a human; a stored bearer token would be a second, worse copy
 *     of the thing it is meant to help debug.
 *  2. PHOTO BYTES — a base64 image inside a JSON payload, or any oversized string
 *     field, is replaced by {omitted, size, sha256}. The digest still answers the
 *     question the monitor is here for ("did the same bytes arrive twice / were
 *     they truncated?") without keeping the bytes.
 *  3. UNBOUNDED VOLUME — the whole body is capped to a byte budget and the cut is
 *     flagged, so one giant request cannot fill the telemetry database.
 *
 * Pure and dependency-free on purpose: it is the one piece whose correctness the
 * whole feature rests on, so it is trivially unit-testable and carries no I/O.
 */
final class Redactor
{
    public const string REDACTED = '[REDACTED]';

    /** Header names (lower-cased) whose VALUE is a credential, never stored. */
    private const array SENSITIVE_HEADERS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'x-api-token',
        'x-auth-token',
        'x-csrf-token',
        'api-key',
    ];

    /**
     * Substrings that mark a body FIELD as a credential. Matched against the key
     * with separators stripped, so `access_token`, `accessToken` and `access-token`
     * all read as "token".
     */
    private const array SENSITIVE_FIELD_MARKERS = [
        'password',
        'passwd',
        'pwd',
        'secret',
        'token',
        'apikey',
        'authorization',
        'credential',
        'privatekey',
    ];

    public function __construct(
        private readonly int $bodyCap = 65536,
        private readonly int $base64FieldThreshold = 2048,
    ) {
    }

    /**
     * @param array<string, list<string|null>|string> $headers HeaderBag::all() shape
     *
     * @return array<string, string>
     */
    public function redactHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $values) {
            $flat = \is_array($values) ? implode(', ', array_map(static fn ($v): string => (string) $v, $values)) : (string) $values;
            $out[$name] = \in_array(strtolower($name), self::SENSITIVE_HEADERS, true) ? self::REDACTED : $flat;
        }

        return $out;
    }

    public function redactBody(string $raw, ?string $contentType): RedactedBody
    {
        if ('' === $raw) {
            return new RedactedBody('', false);
        }

        $type = strtolower($contentType ?? '');

        if (str_contains($type, 'json')) {
            $decoded = json_decode($raw, true);
            if (\is_array($decoded)) {
                $walked = $this->walk($decoded);
                $encoded = json_encode($walked, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);

                return $this->cap(\is_string($encoded) ? $encoded : $raw);
            }

            // Not decodable — an invalid payload is exactly the case we are here
            // to see, so keep it verbatim (capped) rather than dropping it.
            return $this->cap($raw);
        }

        if (str_contains($type, 'x-www-form-urlencoded')) {
            parse_str($raw, $parsed);
            $walked = $this->walk($parsed);

            /** @var array<string, mixed> $walked */
            return $this->cap(http_build_query($walked));
        }

        return $this->cap($raw);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function walk(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (\is_string($key) && $this->isSensitiveKey($key)) {
                $out[$key] = self::REDACTED;

                continue;
            }
            if (\is_array($value)) {
                $out[$key] = $this->walk($value);

                continue;
            }
            if (\is_string($value) && \strlen($value) >= $this->base64FieldThreshold) {
                $out[$key] = $this->omit($value);

                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /** @return array{omitted: true, size: int, sha256: string} */
    private function omit(string $value): array
    {
        return [
            'omitted' => true,
            'size' => \strlen($value),
            'sha256' => hash('sha256', $value),
        ];
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = str_replace(['_', '-', ' '], '', strtolower($key));
        foreach (self::SENSITIVE_FIELD_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function cap(string $body): RedactedBody
    {
        if (\strlen($body) > $this->bodyCap) {
            return new RedactedBody(substr($body, 0, $this->bodyCap), true);
        }

        return new RedactedBody($body, false);
    }
}
