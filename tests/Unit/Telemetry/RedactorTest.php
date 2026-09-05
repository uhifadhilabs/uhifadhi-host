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
use Uhifadhi\Telemetry\Capture\Redactor;

/**
 * The redactor is the privacy boundary: nothing credential-shaped and no photo
 * byte may pass it. These tests assert that boundary HARD — not "a marker is
 * present" but "the secret is nowhere in the output" — because the whole point
 * of the monitor is that it can be read by a human without leaking what it saw.
 */
final class RedactorTest extends TestCase
{
    private Redactor $redactor;

    protected function setUp(): void
    {
        // Small caps so the size behaviour is testable without megabyte strings.
        $this->redactor = new Redactor(bodyCap: 256, base64FieldThreshold: 64);
    }

    public function testRedactsAuthorizationAndCookieHeadersAndKeepsOthers(): void
    {
        $out = $this->redactor->redactHeaders([
            'authorization' => ['Bearer super-secret-token-value'],
            'cookie' => ['SESSION=deadbeef'],
            'set-cookie' => ['SESSION=deadbeef; HttpOnly'],
            'x-api-key' => ['k-9999'],
            'user-agent' => ['Doria/1.4 (Android 13)'],
            'content-type' => ['application/json'],
        ]);

        self::assertSame(Redactor::REDACTED, $out['authorization']);
        self::assertSame(Redactor::REDACTED, $out['cookie']);
        self::assertSame(Redactor::REDACTED, $out['set-cookie']);
        self::assertSame(Redactor::REDACTED, $out['x-api-key']);
        self::assertSame('Doria/1.4 (Android 13)', $out['user-agent']);
        self::assertSame('application/json', $out['content-type']);

        // The token value itself must appear nowhere in the serialized headers.
        self::assertStringNotContainsString('super-secret-token-value', json_encode($out) ?: '');
        self::assertStringNotContainsString('deadbeef', json_encode($out) ?: '');
    }

    public function testRedactsPasswordTokenAndSecretFieldsIncludingNested(): void
    {
        $json = json_encode([
            'email' => 'ranger@example.org',
            'password' => 'hunter2-the-real-password',
            'auth' => [
                'access_token' => 'AT-abcdef',
                'refresh_token' => 'RT-ghijkl',
                'clientSecret' => 'CS-mnopqr',
            ],
            'note' => 'keep me',
        ], \JSON_THROW_ON_ERROR);

        $result = $this->redactor->redactBody($json, 'application/json');

        foreach (['hunter2-the-real-password', 'AT-abcdef', 'RT-ghijkl', 'CS-mnopqr'] as $secret) {
            self::assertStringNotContainsString($secret, $result->body, $secret.' leaked');
        }
        self::assertStringContainsString('ranger@example.org', $result->body);
        self::assertStringContainsString('keep me', $result->body);
        self::assertStringContainsString(Redactor::REDACTED, $result->body);
    }

    public function testReplacesLargeBase64PhotoFieldWithDigestAndDropsBytes(): void
    {
        $photoBytes = random_bytes(4096);            // a "photo"
        $b64 = base64_encode($photoBytes);           // as it travels in JSON
        $json = json_encode(['photo' => $b64, 'species' => 'elephant'], \JSON_THROW_ON_ERROR);

        $result = $this->redactor->redactBody($json, 'application/json');

        // The bytes are gone; a digest + size stand in their place.
        self::assertStringNotContainsString($b64, $result->body, 'photo bytes leaked into storage');
        self::assertStringContainsString('sha256', $result->body);
        self::assertStringContainsString('omitted', $result->body);

        $decoded = json_decode($result->body, true);
        self::assertIsArray($decoded);
        self::assertSame('elephant', $decoded['species']);
        $photo = $decoded['photo'];
        self::assertIsArray($photo);
        self::assertTrue($photo['omitted']);
        self::assertSame(hash('sha256', $b64), $photo['sha256']);
        self::assertSame(\strlen($b64), $photo['size']);
    }

    public function testCapsOversizeBodyAndFlagsTruncation(): void
    {
        $big = str_repeat('A', 5000);
        $result = $this->redactor->redactBody($big, 'text/plain');

        self::assertTrue($result->truncated);
        self::assertLessThanOrEqual(256, \strlen($result->body));
    }

    public function testShortBodyIsNotTruncated(): void
    {
        $result = $this->redactor->redactBody('{"ok":true}', 'application/json');

        self::assertFalse($result->truncated);
        self::assertStringContainsString('ok', $result->body);
    }

    public function testRedactsSensitiveFieldsInFormUrlEncodedBody(): void
    {
        $result = $this->redactor->redactBody(
            'email=ranger%40example.org&password=hunter2-secret&token=T-123',
            'application/x-www-form-urlencoded',
        );

        self::assertStringNotContainsString('hunter2-secret', $result->body);
        self::assertStringNotContainsString('T-123', $result->body);
        self::assertStringContainsString('ranger', $result->body);
    }

    public function testInvalidJsonIsKeptAsTextNotCrashed(): void
    {
        $result = $this->redactor->redactBody('{not valid json', 'application/json');

        self::assertStringContainsString('not valid json', $result->body);
        self::assertFalse($result->truncated);
    }
}
