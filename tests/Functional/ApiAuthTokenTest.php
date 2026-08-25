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

namespace Uhifadhi\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Uhifadhi\Entity\ApiToken;
use Uhifadhi\Entity\User;
use Uhifadhi\Factory\UserFactory;
use Uhifadhi\Service\ApiTokenManager;
use Zenstruck\Foundry\Test\Factories;

/**
 * `POST /api/auth/token` — API-CONTRACT.md §2 — and the firewall around it.
 *
 * The token is the only credential a phone carries into the bush, so what is
 * asserted here is not just "it works": it is that a bad passcode says nothing
 * useful, that the token is long-lived, that it is not recoverable from the
 * database, and that a protected endpoint means it.
 */
final class ApiAuthTokenTest extends WebTestCase
{
    use Factories;

    private const string PASSCODE = 'correct horse battery';

    /**
     * EVERY TEST STARTS WITH A FULL RATE-LIMIT BUDGET.
     *
     * The limiters (config/packages/rate_limiter.yaml) count into `cache.rate_limiter`, which
     * inherits cache.app — the FILESYSTEM pool — so a fixed window OUTLIVES THE PROCESS that
     * opened it. Two suite runs inside one minute then share one budget, and these tests fail on
     * the second for reasons that have nothing to do with the code under test: every request here
     * comes from 127.0.0.1, and the per-IP limiter allows 20 a minute.
     *
     * Clearing the pool is the whole fix, and it is deliberately done HERE rather than by giving
     * the limiter a per-process store: the filesystem pool is what production uses, and the
     * throttling test below needs the count to survive the kernel reboot BETWEEN its six
     * requests. An in-memory adapter would be wiped by each reboot, so the sixth attempt would
     * never be throttled and the assertion would pass while proving nothing. The limits, the
     * policy and the window stay exactly as configured; only the leftovers go.
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        $pool = static::getContainer()->get('cache.rate_limiter');
        \assert($pool instanceof CacheItemPoolInterface);
        $pool->clear();
        self::ensureKernelShutdown();
    }

    public function testGoodCredentialsMintATokenInTheContractsShape(): void
    {
        $client = static::createClient();
        $this->ranger($client, 'sl-0142', 'laizer@authority.go.tz');

        $this->postToken($client, ['rangerId' => 'sl-0142', 'passcode' => self::PASSCODE, 'deviceId' => 'device-1', 'deviceName' => 'Doria on Pixel 7a']);

        self::assertResponseIsSuccessful();
        $body = $this->payload($client);

        self::assertArrayHasKey('token', $body);
        self::assertIsString($body['token']);
        self::assertNotSame('', $body['token']);

        // ISO-8601 UTC with a literal Z (§1) — never an offset.
        self::assertIsString($body['expiresAt']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $body['expiresAt']);

        self::assertSame(['id' => 'sl-0142', 'name' => 'S. Laizer'], $body['ranger']);
    }

    public function testTheTokenOutlivesAPostingRatherThanASession(): void
    {
        $client = static::createClient();
        $this->ranger($client, 'sl-0143', 'long@authority.go.tz');

        $this->postToken($client, ['rangerId' => 'sl-0143', 'passcode' => self::PASSCODE]);

        $expiresAt = new \DateTimeImmutable(self::stringAt($this->payload($client), 'expiresAt'));

        // A ranger cannot re-authenticate from the bush and there is no refresh
        // call, so anything measured in hours would strand recorded work.
        self::assertGreaterThan(
            new \DateTimeImmutable('+60 days'),
            $expiresAt,
            'A field token must be long-lived; see API-CONTRACT.md §2.',
        );
    }

    public function testAnEmailAddressWorksAsTheRangerIdForStaffWithoutAServiceNumber(): void
    {
        $client = static::createClient();
        $this->ranger($client, null, 'office@authority.go.tz');

        $this->postToken($client, ['rangerId' => 'office@authority.go.tz', 'passcode' => self::PASSCODE]);

        self::assertResponseIsSuccessful();
        $ranger = $this->payload($client)['ranger'];
        self::assertSame('office@authority.go.tz', self::stringAt($ranger, 'id'));
    }

    public function testAWrongPasscodeIsRefusedInTheContractsErrorShape(): void
    {
        $client = static::createClient();
        $this->ranger($client, 'sl-0144', 'wrong@authority.go.tz');

        $this->postToken($client, ['rangerId' => 'sl-0144', 'passcode' => 'not it']);

        self::assertResponseStatusCodeSame(401);
        $body = $this->payload($client);

        self::assertSame('invalid_credentials', $body['code']);
        // §10: the app stops and asks the ranger to sign in — it never loops.
        self::assertFalse($body['retryable']);
        self::assertArrayHasKey('message', $body);
        self::assertArrayHasKey('details', $body);
    }

    public function testAnUnknownRangerIsRefusedIdenticallyToAWrongPasscode(): void
    {
        $client = static::createClient();
        $this->ranger($client, 'sl-0145', 'known@authority.go.tz');

        $this->postToken($client, ['rangerId' => 'sl-0145', 'passcode' => 'not it']);
        $wrongPasscode = $this->payload($client);

        $this->postToken($client, ['rangerId' => 'nobody-at-all', 'passcode' => self::PASSCODE]);
        $unknownRanger = $this->payload($client);

        self::assertResponseStatusCodeSame(401);
        // Identical answers, so the endpoint cannot be used to enumerate who
        // works here.
        self::assertSame($wrongPasscode['code'], $unknownRanger['code']);
        self::assertSame($wrongPasscode['message'], $unknownRanger['message']);
    }

    public function testTheTokenIsStoredOnlyAsAHash(): void
    {
        $client = static::createClient();
        $this->ranger($client, 'sl-0146', 'hash@authority.go.tz');

        $this->postToken($client, ['rangerId' => 'sl-0146', 'passcode' => self::PASSCODE]);
        $plaintext = self::stringAt($this->payload($client), 'token');

        $stored = $this->onlyToken();
        self::assertNotSame($plaintext, $stored->getTokenHash(), 'The token itself is in the database.');
        self::assertSame(hash('sha256', $plaintext), $stored->getTokenHash());
    }

    public function testSigningInAgainOnTheSameDeviceRotatesItsTokenRatherThanAddingOne(): void
    {
        $client = static::createClient();
        $this->ranger($client, 'sl-0147', 'rotate@authority.go.tz');

        $this->postToken($client, ['rangerId' => 'sl-0147', 'passcode' => self::PASSCODE, 'deviceId' => 'device-9']);
        $first = self::stringAt($this->payload($client), 'token');

        $this->postToken($client, ['rangerId' => 'sl-0147', 'passcode' => self::PASSCODE, 'deviceId' => 'device-9']);
        $second = self::stringAt($this->payload($client), 'token');

        self::assertNotSame($first, $second);
        // Exactly one row, or re-signing in left a live credential behind.
        $this->onlyToken();
    }

    public function testSigningInStillWorksWhileTheOldTokenIsBeingSent(): void
    {
        $client = static::createClient();
        $this->ranger($client, 'sl-0148', 'stale@authority.go.tz');

        // The phone still holds an expired token and sends it on every request.
        // Sign-in must not be what that stale header breaks.
        $client->request(
            'POST',
            '/api/auth/token',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer a-token-that-expired-weeks-ago',
            ],
            content: json_encode(['rangerId' => 'sl-0148', 'passcode' => self::PASSCODE], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
    }

    public function testAProtectedEndpointNeedsAToken(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/areas/mine', server: ['HTTP_ACCEPT' => 'application/json']);

        // 401, not 403: the app shows different things for the two.
        self::assertResponseStatusCodeSame(401);
        self::assertSame('unauthorized', self::stringAt($this->payload($client), 'code'));
    }

    public function testAProtectedEndpointRejectsAGarbageToken(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/areas/mine', server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer nonsense',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAMintedTokenOpensAProtectedEndpoint(): void
    {
        $client = static::createClient();
        $this->ranger($client, 'sl-0149', 'works@authority.go.tz');

        $this->postToken($client, ['rangerId' => 'sl-0149', 'passcode' => self::PASSCODE]);
        $token = self::stringAt($this->payload($client), 'token');

        $client->request('GET', '/api/areas/mine', server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testARevokedTokenStopsWorking(): void
    {
        $client = static::createClient();
        $user = $this->ranger($client, 'sl-0150', 'revoked@authority.go.tz');

        /** @var ApiTokenManager $tokens */
        $tokens = static::getContainer()->get(ApiTokenManager::class);
        [$plaintext, $token] = $tokens->issue($user, 'device-x');

        $token->revoke();
        $this->entityManager()->flush();

        $client->request('GET', '/api/areas/mine', server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$plaintext,
        ]);

        // The whole reason this is a database row and not a JWT: a lost handset
        // can be withdrawn before its expiry.
        self::assertResponseStatusCodeSame(401);
    }

    public function testAMissingPasscodeIsAValidationFailureInTheContractsShape(): void
    {
        $client = static::createClient();

        $this->postToken($client, ['rangerId' => 'sl-0142']);

        self::assertResponseStatusCodeSame(422);
        $body = $this->payload($client);
        self::assertSame('invalid_payload', $body['code']);
        self::assertFalse($body['retryable']);
    }

    /** The entity manager, typed — static::getContainer()->get() returns object. */
    private function entityManager(): EntityManagerInterface
    {
        $manager = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    /** The single token row, asserted to be single. */
    private function onlyToken(): ApiToken
    {
        $tokens = $this->entityManager()->getRepository(ApiToken::class)->findAll();
        self::assertCount(1, $tokens);

        return $tokens[0];
    }

    /** @param array<string, mixed> $body */
    private function postToken(KernelBrowser $client, array $body): void
    {
        $client->request(
            'POST',
            '/api/auth/token',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    private static function stringAt(mixed $row, string $key): string
    {
        self::assertIsArray($row);
        $value = $row[$key] ?? null;
        self::assertIsString($value, \sprintf('"%s" is missing or not a string.', $key));

        return $value;
    }

    /** @return array<string, mixed> */
    private function payload(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function ranger(KernelBrowser $client, ?string $rangerCode, string $email): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);

        $user = UserFactory::createOne([
            'email' => $email,
            'firstName' => 'S.',
            'lastName' => 'Laizer',
            'rangerCode' => $rangerCode,
        ]);

        $user->setPassword($hasher->hashPassword($user, self::PASSCODE));
        $this->entityManager()->flush();

        return $user;
    }

    public function testASixthAttemptInsideAMinuteIsThrottled(): void
    {
        $client = static::createClient();

        for ($i = 1; $i <= 5; ++$i) {
            $client->request('POST', '/api/auth/token', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['rangerId' => 'nobody@uhifadhi.test', 'passcode' => 'wrong']));
            self::assertSame(401, $client->getResponse()->getStatusCode(), 'attempt '.$i.' is an ordinary refusal');
        }

        $client->request('POST', '/api/auth/token', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['rangerId' => 'nobody@uhifadhi.test', 'passcode' => 'wrong']));
        self::assertSame(429, $client->getResponse()->getStatusCode(), 'the sixth attempt inside the window is throttled');

        /** @var array{code: string, retryable: bool} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('too_many_attempts', $body['code']);
        self::assertTrue($body['retryable']);
    }
}
