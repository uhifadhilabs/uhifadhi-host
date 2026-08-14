<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * The `app:module:ingest` CLI trigger. It resolves the area (uuid / id / name) before touching the
 * engine, so an unknown area fails cleanly without a dispatch. The happy path (which calls the live
 * engine over HTTP) is exercised by the handler test with a mocked engine, not here.
 */
final class RunModuleIngestionCommandTest extends KernelTestCase
{
    use Factories;

    public function testFailsCleanlyWhenTheAreaIsNotFound(): void
    {
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:module:ingest'));

        $status = $tester->execute(['aoi' => 'no-such-area', 'module' => 'landcover']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('No AreaOfInterest', $tester->getDisplay());
    }
}
