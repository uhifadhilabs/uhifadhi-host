<?php

declare(strict_types=1);

namespace Uhifadhi\Tests\Integration\Seeding;

use Uhifadhi\Spatial\Repository\AreaOfInterestRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * app:seed:area must create the area with the EXACT uuid it was given (so config
 * addressing it by uuid keeps resolving) and be idempotent — re-running never
 * duplicates it. Real PostGIS, so the boundary round-trips into the geometry column.
 */
final class SeedAreaCommandTest extends KernelTestCase
{
    private const string FIXED_UUID = '11111111-2222-4333-8444-555555555555';

    private function tester(): CommandTester
    {
        self::bootKernel();

        return new CommandTester((new Application(self::$kernel))->find('app:seed:area'));
    }

    private function boundaryFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'seedarea').'.geojson';
        file_put_contents($file, (string) json_encode([
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[[35.0, -3.4], [35.8, -3.4], [35.8, -2.9], [35.0, -2.9], [35.0, -3.4]]],
            ],
        ]));

        return $file;
    }

    public function testItCreatesTheAreaWithTheGivenFixedUuidAndIsIdempotent(): void
    {
        $file = $this->boundaryFile();
        $options = ['--uuid' => self::FIXED_UUID, '--name' => 'Fixed Area', '--file' => $file];

        $first = $this->tester();
        self::assertSame(Command::SUCCESS, $first->execute($options));
        self::assertStringContainsString('Created', $first->getDisplay());

        $repository = self::getContainer()->get(AreaOfInterestRepository::class);
        $area = $repository->findOneBy(['uuid' => Uuid::fromString(self::FIXED_UUID)]);
        self::assertNotNull($area, 'area is addressable by the exact uuid given');
        self::assertSame('Fixed Area', $area->getName());

        // Second run is a no-op — still exactly one area with that uuid.
        $second = $this->tester();
        self::assertSame(Command::SUCCESS, $second->execute($options));
        self::assertStringContainsString('Already present', $second->getDisplay());
        self::assertCount(1, $repository->findBy(['uuid' => Uuid::fromString(self::FIXED_UUID)]));

        unlink($file);
    }

    public function testAMissingFileFails(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute(['--uuid' => self::FIXED_UUID, '--file' => '/nope/nothing.geojson']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Cannot read', $tester->getDisplay());
    }
}
