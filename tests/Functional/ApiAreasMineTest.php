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

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Uhifadhi\Entity\User;
use Uhifadhi\Factory\AreaOfInterestFactory;
use Uhifadhi\Factory\UserFactory;
use Uhifadhi\Service\ApiTokenManager;
use Zenstruck\Foundry\Test\Factories;

/**
 * `GET /api/areas/mine` — API-CONTRACT.md §3: everything the field app caches at
 * sign-in so it can work with no network for the rest of the day.
 *
 * This endpoint lives in the HOST because areas, boundaries and the staff roster
 * are host entities — no module owns them, and a module that served them would
 * be answering for data it does not hold.
 */
final class ApiAreasMineTest extends WebTestCase
{
    use Factories;

    public function testItReturnsTheAreasTheAppCaches(): void
    {
        $client = static::createClient();
        AreaOfInterestFactory::createOne(['name' => 'Aurora Basin']);
        $this->get($client, $this->ranger());

        self::assertResponseIsSuccessful();
        $body = $this->payload($client);

        $areas = self::listAt($body, 'areas');
        self::assertCount(1, $areas);

        $area = self::rowAt($areas, 0);

        // The contract's literal field names — the app is already built to these.
        self::assertSame(
            ['id', 'name', 'areaKm2', 'stations', 'team', 'boundary'],
            array_keys($area),
        );

        self::assertSame('Aurora Basin', $area['name']);
        self::assertIsFloat($area['areaKm2']);
        self::assertGreaterThan(0.0, $area['areaKm2'], 'The area is measured on the spheroid, not left at zero.');
    }

    public function testTheBoundaryIsGeoJsonInLonLatOrder(): void
    {
        $client = static::createClient();
        AreaOfInterestFactory::createOne(['name' => 'Aurora Basin']);
        $this->get($client, $this->ranger());

        $area = self::rowAt(self::listAt($this->payload($client), 'areas'), 0);
        $boundary = $area['boundary'];
        self::assertIsArray($boundary);
        self::assertArrayHasKey('type', $boundary);
        self::assertArrayHasKey('coordinates', $boundary);

        // Either is legal GeoJSON and both occur: the column is a MultiPolygon,
        // but PostGIS returns a plain Polygon for a single-part area once it has
        // been simplified. The app must accept both — noted as a deviation in
        // API-CONTRACT.md, whose example shows only Polygon.
        self::assertContains($boundary['type'], ['Polygon', 'MultiPolygon']);

        // RFC 7946 order, which is also PostGIS's: longitude first. The factory
        // area sits around lon 35, lat -3 — so a swapped pair would be obvious.
        $first = self::firstCoordinate($boundary['coordinates']);

        self::assertGreaterThan(30.0, $first[0], 'Longitude must come first.');
        self::assertLessThan(0.0, $first[1], 'Latitude must come second.');
    }

    public function testTheRosterUsesTheSameRangerIdsThatComeBackOnAPatrol(): void
    {
        $client = static::createClient();
        AreaOfInterestFactory::createOne();

        UserFactory::createOne(['firstName' => 'N.', 'lastName' => 'Kileo', 'rangerCode' => 'nk-0088', 'email' => 'kileo@authority.go.tz']);

        $this->get($client, $this->ranger());

        $area = self::rowAt(self::listAt($this->payload($client), 'areas'), 0);
        $team = $area['team'];
        self::assertIsArray($team);

        $ids = array_column($team, 'id');
        self::assertContains('nk-0088', $ids, 'A ranger is named by the service number they sign in with.');

        foreach ($team as $member) {
            self::assertIsArray($member);
            self::assertSame(['id', 'name'], array_keys($member));
        }
    }

    public function testItNeedsAToken(): void
    {
        $client = static::createClient();
        AreaOfInterestFactory::createOne();

        $client->request('GET', '/api/areas/mine', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * A nested array from the decoded body, narrowed so the assertions below
     * are about the contract rather than about PHP's type system.
     *
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    private static function listAt(array $body, string $key): array
    {
        $value = $body[$key] ?? null;
        self::assertIsArray($value, \sprintf('"%s" is missing from the response.', $key));

        return $value;
    }

    /** @return array<string, mixed> */
    private static function rowAt(mixed $list, int $index): array
    {
        self::assertIsArray($list);
        $row = $list[$index] ?? null;
        self::assertIsArray($row);

        /** @var array<string, mixed> $row */
        return $row;
    }

    /**
     * The first [lon, lat] pair, however deeply the geometry nests it — one
     * level for a Polygon's ring, two for a MultiPolygon's.
     *
     * @return array{0: float, 1: float}
     */
    private static function firstCoordinate(mixed $coordinates): array
    {
        while (\is_array($coordinates) && \is_array($coordinates[0] ?? null)) {
            $coordinates = $coordinates[0];
        }

        self::assertIsArray($coordinates);
        self::assertIsNumeric($coordinates[0] ?? null);
        self::assertIsNumeric($coordinates[1] ?? null);

        return [(float) $coordinates[0], (float) $coordinates[1]];
    }

    private function ranger(): User
    {
        return UserFactory::createOne([
            'firstName' => 'S.',
            'lastName' => 'Laizer',
            'rangerCode' => 'sl-0142',
            'email' => 'laizer@authority.go.tz',
        ]);
    }

    private function get(KernelBrowser $client, User $user): void
    {
        /** @var ApiTokenManager $tokens */
        $tokens = static::getContainer()->get(ApiTokenManager::class);
        [$plaintext] = $tokens->issue($user, 'device-1', 'Doria on Pixel 7a');

        $client->request('GET', '/api/areas/mine', server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$plaintext,
            'HTTP_X_DORIA_DEVICE' => 'device-1',
            'HTTP_X_DORIA_VERSION' => '0.1.0',
        ]);
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
