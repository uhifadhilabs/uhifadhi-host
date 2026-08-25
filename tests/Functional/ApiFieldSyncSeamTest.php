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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;
use Uhifadhi\Entity\User;
use Uhifadhi\Factory\AreaOfInterestFactory;
use Uhifadhi\Factory\PositionFactory;
use Uhifadhi\Factory\UserFactory;
use Uhifadhi\Service\ApiTokenManager;
use Zenstruck\Foundry\Test\Factories;

/**
 * The seam itself, exercised where the two halves actually meet.
 *
 * The host owns api-platform, the firewall and the tokens; the patrol MODULE
 * owns `/api/patrols` and declares the `patrols.record` permission; the host's
 * voter decides who holds it. Each side is tested on its own — the module has a
 * full sync suite of its own — but nothing else proves the join: that a real
 * bearer token minted here reaches a route this application never configured,
 * and that the module's declared permission is enforced by the host's real
 * PermissionVoter rather than by a fixture standing in for it.
 *
 * If this file starts failing, the seam is broken even when both suites are
 * green.
 */
final class ApiFieldSyncSeamTest extends WebTestCase
{
    use Factories;

    private const string PATROL_UUID = '8f1f4e02-6b1a-4f34-8f8f-1a0f19a1c111';

    public function testAModuleRouteExistsWithoutTheHostConfiguringIt(): void
    {
        $client = static::createClient();

        // The host's config mentions nothing patrol-shaped: api-platform finds
        // <bundle>/ApiResource by itself. So the route must simply be there.
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        self::assertNotNull(
            $router->getRouteCollection()->get('_api_/patrols_post'),
            'The patrol module\'s endpoints did not reach the host\'s /api.',
        );
    }

    public function testARangerWithTheModulesPermissionCanRecordAPatrol(): void
    {
        $client = static::createClient();
        $area = AreaOfInterestFactory::createOne();

        $this->postPatrol($client, $this->rangerWhoMayRecord(), (string) $area->getUuid()?->toRfc4122());

        self::assertResponseStatusCodeSame(201);
        $body = $this->payload($client);
        self::assertSame(self::PATROL_UUID, $body['uuid']);
        self::assertSame('recording', $body['status']);
        self::assertFalse($body['duplicate']);
    }

    public function testTheSameRequestTwiceStillYieldsOnePatrol(): void
    {
        $client = static::createClient();
        $area = AreaOfInterestFactory::createOne();
        $ranger = $this->rangerWhoMayRecord();
        $areaId = (string) $area->getUuid()?->toRfc4122();

        $this->postPatrol($client, $ranger, $areaId);
        self::assertResponseStatusCodeSame(201);
        $reference = $this->payload($client)['reference'];

        $this->postPatrol($client, $ranger, $areaId);

        self::assertResponseStatusCodeSame(200);
        $body = $this->payload($client);
        self::assertTrue($body['duplicate']);
        self::assertSame($reference, $body['reference']);
    }

    public function testARangerWithoutThePermissionIsRefusedByTheHostsVoter(): void
    {
        $client = static::createClient();
        $area = AreaOfInterestFactory::createOne();

        // A real Staff user with a real Position that does NOT hold
        // patrols.record — decided by Uhifadhi\Security\PermissionVoter.
        $ranger = UserFactory::createOne(['rangerCode' => 'nk-0088', 'position' => PositionFactory::createOne()]);

        $this->postPatrol($client, $ranger, (string) $area->getUuid()?->toRfc4122());

        self::assertResponseStatusCodeSame(403);
        self::assertSame('forbidden', $this->payload($client)['code']);
    }

    public function testAModuleRouteWithoutATokenIsUnauthorised(): void
    {
        $client = static::createClient();
        AreaOfInterestFactory::createOne();

        $client->request('POST', '/api/patrols', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: '{}');

        // 401 from the host's firewall, before the module is ever reached.
        self::assertResponseStatusCodeSame(401);
        self::assertSame('unauthorized', $this->payload($client)['code']);
    }

    /**
     * A Staff user whose Position grants the permission the MODULE declares.
     * Stored by value, because PermissionEnum is the host's fixed catalogue and
     * a module's permission is deliberately not in it.
     */
    private function rangerWhoMayRecord(): User
    {
        $position = PositionFactory::createOne();
        $position->setPermissionValues(['patrols.record']);

        $user = UserFactory::createOne(['rangerCode' => 'sl-0142', 'position' => $position]);

        $manager = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->flush();

        return $user;
    }

    private function postPatrol(KernelBrowser $client, User $ranger, string $areaId): void
    {
        /** @var ApiTokenManager $tokens */
        $tokens = static::getContainer()->get(ApiTokenManager::class);
        [$plaintext] = $tokens->issue($ranger, 'device-1', 'Doria on Pixel 7a');

        $client->request(
            'POST',
            '/api/patrols',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$plaintext,
                'HTTP_X_DORIA_DEVICE' => 'device-1',
                'HTTP_X_DORIA_VERSION' => '0.1.0',
            ],
            content: json_encode([
                'clientUuid' => self::PATROL_UUID,
                'areaId' => $areaId,
                'type' => 'foot',
                'stationId' => 'north-gate',
                'team' => ['sl-0142'],
                'startedAt' => '2026-08-23T06:44:12Z',
                'endedAt' => '2026-08-23T09:54:38Z',
                'droneId' => null,
                'mission' => null,
                'deviceId' => 'device-1',
                'appVersion' => '0.1.0',
            ], \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function payload(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
