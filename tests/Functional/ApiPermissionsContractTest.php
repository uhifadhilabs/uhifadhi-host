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
use Uhifadhi\Entity\Position;
use Uhifadhi\Entity\User;
use Uhifadhi\Enum\PermissionEnum;
use Uhifadhi\Enum\TeamRoleEnum;
use Uhifadhi\Factory\PositionFactory;
use Uhifadhi\Factory\UserFactory;
use Zenstruck\Foundry\Test\Factories;

/**
 * THE EARLY SIGNAL — API-CONTRACT.md §2A.
 *
 * D1 in that document said the sign-in endpoint could not answer "may this account record
 * patrols?", because the host is deliberately blind to modules; the refusal therefore arrived at
 * the first `POST /api/patrols`, hours later and possibly out of signal. §2A is the app's answer
 * to D1's closing offer: it wants the signal BEFORE the ranger walks out, because a patrol that
 * cannot be saved is a day of walking thrown away.
 *
 * The host still learns nothing about patrols. It sends the account's WHOLE permission set —
 * catalogue values, core and module-declared alike — and the app reads the one member it cares
 * about (`patrols.record`). That is the same trick the permission catalogue has always turned:
 * a module's permission is a catalogue entry like any other, and the host never asks what it means.
 *
 * The contract's three rules, and this file pins the two the server owns:
 *   - an EMPTY array is a refusal (the host knows about permissions; this account holds none);
 *   - a MISSING array means permitted, which is why the field is always sent here — a host that
 *     sends it must send it for everyone, or a permitted ranger reads silence as a grant on one
 *     request and a refusal on the next.
 * The third rule (404 on /api/me reads as "missing") belongs to older deployments, not this one.
 */
final class ApiPermissionsContractTest extends WebTestCase
{
    use Factories;

    private const string PASSCODE = 'correct horse battery';

    /** @see ApiAuthTokenTest::setUp() — the rate limiter's window outlives the process. */
    protected function setUp(): void
    {
        parent::setUp();

        $client = static::createClient();
        /** @var CacheItemPoolInterface $pool */
        $pool = $client->getContainer()->get('cache.rate_limiter');
        $pool->clear();
        self::ensureKernelShutdown();
    }

    public function testSignInCarriesTheAccountsPermissionsSoTheRefusalArrivesBeforeTheWalk(): void
    {
        $client = static::createClient();
        $recorder = PositionFactory::createOne(['name' => 'Field Ranger']);
        $recorder->setPermissionValues([PermissionEnum::AreaView->value]);
        $this->ranger($client, 'sl-0142', 'recorder@authority.go.tz', $recorder);

        $this->signIn($client, 'sl-0142');

        // The field must always be sent by a host that sends it at all.
        self::assertSame([PermissionEnum::AreaView->value], $this->permissionsOf($client));
        // ranger.role is load-bearing in §2A: the blocked screen names the POSITION to change.
        self::assertSame('Field Ranger', $this->rangerOf($client)['role']);
    }

    /**
     * An empty array is the refusal. It must not be omitted — omission means "permitted".
     */
    public function testAnAccountThatHoldsNothingIsSentAnEmptyArrayRatherThanNoField(): void
    {
        $client = static::createClient();
        $this->ranger($client, 'sl-0143', 'nothing@authority.go.tz', null);

        $this->signIn($client, 'sl-0143');

        self::assertSame([], $this->permissionsOf($client), 'an empty array is a refusal; a missing field is a grant');
    }

    /**
     * A MANAGING TIER holds the catalogue entire — the same rule the PermissionVoter applies one
     * attribute at a time, enumerated. "Managing" is Super Admin and Admin only
     * ({@see TeamRoleEnum::canManageContent()}); a Manager administers the team but still holds
     * exactly their position's permissions, so they are no blanket holder here either.
     */
    public function testAManagingTierIsSentTheWholeCatalogue(): void
    {
        $client = static::createClient();
        $this->ranger($client, 'sl-0144', 'boss@authority.go.tz', null, TeamRoleEnum::Admin);

        $this->signIn($client, 'sl-0144');

        $permissions = $this->permissionsOf($client);
        self::assertContains(PermissionEnum::AreaView->value, $permissions);
        self::assertGreaterThan(1, \count($permissions));
    }

    // ------------------------------------------------------------------ /api/me

    /**
     * THE SAME FACTS, ASKED AGAIN. The app calls this at the start of every sync run, and from
     * the two "check again" controls the design gives a blocked ranger — so a permission granted
     * in the web app reaches the phone with no sign-out and no re-install.
     */
    public function testMeRepeatsTheRangerAndPermissionsForAnAlreadySignedInDevice(): void
    {
        $client = static::createClient();
        $recorder = PositionFactory::createOne(['name' => 'Field Ranger']);
        $recorder->setPermissionValues([PermissionEnum::AreaView->value]);
        $this->ranger($client, 'sl-0145', 'sync@authority.go.tz', $recorder);
        $token = $this->signInToken($client, 'sl-0145');

        $this->getMe($client, $token);

        self::assertResponseIsSuccessful();
        self::assertSame(['id' => 'sl-0145', 'name' => 'S. Laizer', 'role' => 'Field Ranger'], $this->rangerOf($client));
        self::assertSame([PermissionEnum::AreaView->value], $this->permissionsOf($client));
    }

    /**
     * A PERMISSION GRANTED IN THE WEB APP REACHES THE PHONE ON THE NEXT SYNC. This is the whole
     * point of asking again rather than trusting what sign-in said months ago.
     */
    public function testAPermissionGrantedAfterSignInIsVisibleOnTheNextSyncWithoutSigningInAgain(): void
    {
        $client = static::createClient();
        $position = PositionFactory::createOne(['name' => 'Field Ranger']);
        $position->setPermissionValues([]);
        $this->ranger($client, 'sl-0146', 'later@authority.go.tz', $position);
        $token = $this->signInToken($client, 'sl-0146');

        // Refused at sign-in…
        $this->getMe($client, $token);
        self::assertSame([], $this->permissionsOf($client));

        // …granted on the web, with the phone untouched. Re-read through the CURRENT kernel: the
        // requests above rebooted it, and the fixture's proxy belongs to a manager that is gone.
        $em = $this->entityManager();
        $granted = $em->getRepository(Position::class)->findOneBy(['name' => 'Field Ranger']);
        \assert($granted instanceof Position);
        $granted->setPermissionValues([PermissionEnum::AreaView->value]);
        $em->flush();

        $this->getMe($client, $token);
        self::assertResponseIsSuccessful();
        self::assertSame([PermissionEnum::AreaView->value], $this->permissionsOf($client));
    }

    public function testMeNeedsAValidToken(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/me', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The permissions array of the last response, as the list the contract says it is.
     *
     * @return list<mixed>
     */
    private function permissionsOf(KernelBrowser $client): array
    {
        $permissions = $this->payload($client)['permissions'] ?? null;
        self::assertIsArray($permissions, 'a host that sends permissions must always send them');

        return array_values($permissions);
    }

    /**
     * The ranger object of the last response.
     *
     * @return array<string, mixed>
     */
    private function rangerOf(KernelBrowser $client): array
    {
        $ranger = $this->payload($client)['ranger'] ?? null;
        self::assertIsArray($ranger);

        /** @var array<string, mixed> $ranger */
        return $ranger;
    }

    /** Ask `/api/me` the way the app does, on every sync. */
    private function getMe(KernelBrowser $client, string $token): void
    {
        $client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_ACCEPT' => 'application/json',
        ]);
    }

    private function signInToken(KernelBrowser $client, string $rangerId): string
    {
        $token = $this->signIn($client, $rangerId)['token'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function signIn(KernelBrowser $client, string $rangerId): array
    {
        $client->request(
            'POST',
            '/api/auth/token',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: (string) json_encode(['rangerId' => $rangerId, 'passcode' => self::PASSCODE], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        return $this->payload($client);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function ranger(
        KernelBrowser $client,
        string $rangerCode,
        string $email,
        ?object $position,
        TeamRoleEnum $tier = TeamRoleEnum::Staff,
    ): User {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);

        $user = UserFactory::createOne([
            'email' => $email,
            'firstName' => 'S.',
            'lastName' => 'Laizer',
            'rangerCode' => $rangerCode,
            'teamRole' => $tier,
            'position' => $position,
        ]);
        $user->setPassword($hasher->hashPassword($user, self::PASSCODE));
        $this->entityManager()->flush();

        return $user;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
