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

namespace Uhifadhi\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Uhifadhi\Entity\Zone;
use Uhifadhi\Factory\AreaOfInterestFactory;
use Uhifadhi\Repository\ZoneRepository;
use Uhifadhi\Tests\ZoneGeometry;
use Zenstruck\Foundry\Test\Factories;

/**
 * The admin path onto zones: `app:zone:import --area <uuid> --file <geojson>`. The area
 * is addressed by uuid (never the sequential id) and the summary reports each imported
 * zone with its geodesic area.
 */
final class ImportZoneCommandTest extends KernelTestCase
{
    use Factories;
    use ZoneGeometry;

    private function commandTester(): CommandTester
    {
        $kernel = self::bootKernel();

        return new CommandTester(new Application($kernel)->find('app:zone:import'));
    }

    private function writeCollection(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'zones').'.geojson';
        file_put_contents($file, (string) json_encode(self::featureCollection([
            self::squareFeature('North', 35.0, -3.4, 35.2, -3.0),
            self::squareFeature('Central', 35.2, -3.4, 35.4, -3.0),
        ]), \JSON_THROW_ON_ERROR));

        return $file;
    }

    public function testItImportsTheFileAndSummarisesTheZones(): void
    {
        $tester = $this->commandTester();
        $area = AreaOfInterestFactory::createOne();
        $file = $this->writeCollection();

        $exit = $tester->execute(['--area' => (string) $area->getUuidString(), '--file' => $file]);
        unlink($file);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('North', $display);
        self::assertStringContainsString('Central', $display);
        self::assertStringContainsString('km²', $display);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $repo = $em->getRepository(Zone::class);
        \assert($repo instanceof ZoneRepository);
        self::assertCount(2, $repo->zonesFor($area));
    }

    public function testAnUnknownAreaUuidFails(): void
    {
        $tester = $this->commandTester();
        $file = $this->writeCollection();

        $exit = $tester->execute(['--area' => '00000000-0000-7000-8000-000000000000', '--file' => $file]);
        unlink($file);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('No area', $tester->getDisplay());
    }

    public function testAMalformedAreaUuidFails(): void
    {
        $tester = $this->commandTester();
        $file = $this->writeCollection();

        $exit = $tester->execute(['--area' => 'not-a-uuid', '--file' => $file]);
        unlink($file);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('uuid', $tester->getDisplay());
    }

    public function testAnOverlappingFileFailsWithoutWritingAnything(): void
    {
        $tester = $this->commandTester();
        $area = AreaOfInterestFactory::createOne();
        $file = tempnam(sys_get_temp_dir(), 'zones').'.geojson';
        file_put_contents($file, (string) json_encode(self::featureCollection([
            self::squareFeature('North', 35.0, -3.4, 35.4, -3.0),
            self::squareFeature('Middle', 35.2, -3.4, 35.6, -3.0),
        ]), \JSON_THROW_ON_ERROR));

        $exit = $tester->execute(['--area' => (string) $area->getUuidString(), '--file' => $file]);
        unlink($file);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Middle', $tester->getDisplay());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $repo = $em->getRepository(Zone::class);
        \assert($repo instanceof ZoneRepository);
        self::assertSame([], $repo->zonesFor($area));
    }
}
